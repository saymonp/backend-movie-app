<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use App\Models\Movie;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Cache;
use App\Services\TmdbService;

class StoreMovieJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * O número de vezes que o job pode ser tentado.
     */
    public $tries = 3;

    public function __construct(protected array $data)
    {
        //
    }

    public function handle(): void
    {
        $tmdb = new TmdbService();

        $generos_ids = $tmdb->getMovieGeneros();

        // 1. Obter detalhes
        $movieResponse = $tmdb->getMovieDetails($this->data['tmdb_id'], $generos_ids);

        if (!$movieResponse) {

            Movie::where('tmdb_id', $this->data['tmdb_id'])
                ->update([
                    'status' => 'erro'
                ]);

            throw new \Exception('TMDB não retornou dados');
        }
        //Log::info('Movie response:', $movieResponse);
        // 2. Validação
        $validator = Validator::make($movieResponse, [
            'tmdb_id' => 'required|integer',
            'imdb_id'           => 'nullable|string',
            'titulo_original'   => 'required|string',
            'titulo_br'         => 'nullable|string',
            'titulo_en'         => 'nullable|string',
            'descricao_br'      => 'nullable|string',
            'descricao_en'      => 'required|string',
            'tagline_br'        => 'nullable|string',
            'tagline_en'        => 'nullable|string',
            'slug_pt'           => 'nullable|string',
            'slug_en'           => 'required|string',
            'rating'            => 'required|numeric',
            'duracao'           => 'required|integer',
            'lingua_origem'     => 'required|string|max:5',
            'release_date'      => 'required|date',
            'homepage'          => 'nullable|url',
            'poster_path_br'    => 'nullable|url',
            'poster_thumb_br'   => 'nullable|url',
            'backdrop_path'     => 'nullable|url',
            'poster_path_us'    => 'nullable|url',
            'poster_thumb_us'   => 'nullable|url',
            'generos'           => 'nullable|array',
            'diretores'         => 'nullable|array',
            'estudios'          => 'nullable|array',
            'paises'            => 'nullable|array',
            'revenue'           => 'nullable|integer',
            'popularity'        => 'nullable|numeric',
            'status'            =>  'nullable|string',
            'colecao'           => 'nullable|array',
            'colecao.nome'      => 'required_with:colecao|string',
            'colecao.tmdb_id'   => 'required_with:colecao|integer',
            'colecao.poster_path' => 'nullable|string',
            'colecao.poster_thumb' => 'nullable|string',
            'colecao.backdrop_path' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            dump('Validator Falhou', $validator->errors()->first());
            Log::warning("Validação falhou para filme TMDB {$this->data['tmdb_id']}: " . $validator->errors()->first());
            return;
        }

        $validated = $validator->validated();

        // 3. Persistência
        $movie = DB::transaction(function () use ($validated) {

            $requiredFields = [
                'titulo_original',
                'descricao_en',
                'slug_en',
                'rating',
                'duracao',
                'release_date'
            ];

            foreach ($requiredFields as $field) {
                if (empty($validated[$field])) {
                    throw new \Exception("Campo obrigatório vazio: {$field}");
                }
            }

            $movieRecord = Movie::where('tmdb_id', $validated['tmdb_id'])
                ->lockForUpdate()
                ->first();

            if (!$movieRecord) {
                $movieRecord = new Movie([
                    'tmdb_id' => $validated['tmdb_id'],
                    'status' => 'processando'
                ]);
            }

            $movieData = [
                'imdb_id' => $validated['imdb_id'],
                'titulo_original' => $validated['titulo_original'],
                'titulo_br' => $validated['titulo_br'],
                'titulo_en' => $validated['titulo_en'],
                'descricao_br' => $validated['descricao_br'],
                'descricao_en' => $validated['descricao_en'],
                'tagline_br' => $validated['tagline_br'],
                'tagline_en' => $validated['tagline_en'],
                'slug_pt' => $validated['slug_pt'],
                'slug_en' => $validated['slug_en'],
                'rating' => $validated['rating'],
                'duracao' => $validated['duracao'],
                'lingua_origem' => $validated['lingua_origem'],
                'release_date' => $validated['release_date'],
                'homepage' => $validated['homepage'],
                'revenue' => $validated['revenue'],
                'popularity' => $validated['popularity'],
            ];

            $movieData = array_filter(
                $movieData,
                fn($value) => !is_null($value) && $value !== ''
            );

            $movieRecord->fill($movieData);
            $movieRecord->save();

            $movieRecord->syncRelationsGeneros('generos', \App\Models\Genero::class, $validated['generos']);
            $movieRecord->syncRelations('diretores', \App\Models\Diretor::class, $validated['diretores'] ?? []);
            $movieRecord->syncRelations('estudios', \App\Models\Estudio::class, $validated['estudios'] ?? []);
            $movieRecord->syncRelations('paises', \App\Models\Pais::class, $validated['paises'] ?? []);
            $movieRecord->syncRelationsColecao($validated['colecao'] ?? []);

            $movieRecord->status = 'processado';
            $movieRecord->save();

            return $movieRecord;
        });
        $movie->refresh();
        Log::info('Movie criado?', ['movie' => $movie]);
        if ($movie) {
            $imageUrls = $this->createImageArray($validated);

            Log::info("Despachando imagens para o filme ID: {$movie->id}");

            ProcessMovieImagesJob::dispatch($movie, $imageUrls)->onQueue('images');
        }

        // 4. Tradução (Se não tem descrição em PT, mas tem em EN)
        if (!filled($validated['descricao_br']) && filled($validated['descricao_en'])) {

            Log::info("Despachando tradução para o filme ID: {$movie->id}");

            // Enviamos o model $movie e o texto original
            ProcessMovieTranslationJob::dispatch($movie, $validated['descricao_en'])
                ->onQueue('translation');
        }
    }

    function createImageArray($dataValidated)
    {
        return [
            'poster_path_br'  => $dataValidated['poster_path_br'] ?? null,
            'poster_thumb_br' => $dataValidated['poster_thumb_br'] ?? null,
            'backdrop_path'   => $dataValidated['backdrop_path'] ?? null,
            'poster_path_us'  => $dataValidated['poster_path_us'] ?? null,
            'poster_thumb_us' => $dataValidated['poster_thumb_us'] ?? null,
            'colecao' => !empty($dataValidated['colecao']) ? [
                'poster_path'   => $dataValidated['colecao']['poster_path'] ?? null,
                'poster_thumb'  => $dataValidated['colecao']['poster_thumb'] ?? null,
                'backdrop_path' => $dataValidated['colecao']['backdrop_path'] ?? null
            ] : null
        ];
    }

    public function failed(\Throwable $exception)
    {
        Log::error("Job falhou fatalmente para Filme TMDB {$this->data['tmdb_id']}: " . $exception->getMessage());
    }
}
