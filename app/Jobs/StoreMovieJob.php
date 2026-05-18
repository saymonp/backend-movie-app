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
            // Se o TMDB falhar, limpa qualquer lixo antes de falhar o job
            $ghostMovie = Movie::where('tmdb_id', $this->data['tmdb_id'])->first();
            if ($ghostMovie) {
                Log::warning("Removendo filme vazio por falha de comunicação com o TMDB. ID TMDB: {$this->data['tmdb_id']}");
                $ghostMovie->delete();
            }

            throw new \Exception("TMDB não retornou dados para o ID: {$this->data['tmdb_id']}");
        }

        // 2. Validação
        $validator = Validator::make($movieResponse, [
            'tmdb_id'               => 'required|integer',
            'imdb_id'               => 'nullable|string',
            'titulo_original'       => 'required|string',
            'titulo_br'             => 'nullable|string',
            'titulo_en'             => 'nullable|string',
            'descricao_br'          => 'nullable|string',
            'descricao_en'          => 'required|string',
            'tagline_br'            => 'nullable|string',
            'tagline_en'            => 'nullable|string',
            'slug_pt'               => 'nullable|string',
            'slug_en'               => 'required|string',
            'rating'                => 'nullable|numeric',
            'duracao'               => 'required|integer',
            'lingua_origem'         => 'required|string|max:5',
            'release_date'          => 'required|date',
            'homepage'              => 'nullable|url',
            'poster_path_br'        => 'nullable|url',
            'poster_thumb_br'       => 'nullable|url',
            'backdrop_path'         => 'nullable|url',
            'poster_path_us'        => 'nullable|url',
            'poster_thumb_us'       => 'nullable|url',
            'generos'               => 'nullable|array',
            'diretores'             => 'nullable|array',
            'estudios'              => 'nullable|array',
            'paises'                => 'nullable|array',
            'revenue'               => 'nullable|integer',
            'popularity'            => 'nullable|numeric',
            'status'                => 'nullable|string',
            'colecao'               => 'nullable|array',
            'colecao.nome'          => 'required_with:colecao|string',
            'colecao.tmdb_id'       => 'required_with:colecao|integer',
            'colecao.poster_path'   => 'nullable|string',
            'colecao.poster_thumb'  => 'nullable|string',
            'colecao.backdrop_path' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            Log::error("Validação falhou para filme TMDB {$this->data['tmdb_id']}: " . $validator->errors()->first());
            
            // Lança uma exceção, força o Job a ir para failed() e apagar o registro vazio.
            throw new \Exception("Dados inválidos vindos do TMDB: " . $validator->errors()->first());
        }

        $validated = $validator->validated();

        // 3. Persistência
        $movie = DB::transaction(function () use ($validated) {

            $requiredFields = [
                'titulo_original',
                'descricao_en',
                'slug_en',
                'duracao',
                'release_date'
            ];

            foreach ($requiredFields as $field) {
                if (empty($validated[$field])) {
                    throw new \Exception("Campo obrigatório vazio na transação: {$field}");
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

            // Filtra nulos e vazios
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

        // Verificação final antes de despachar os sub-jobs
        if ($movie && !is_null($movie->titulo_original) && $movie->status === 'processado') {
            $imageUrls = $this->createImageArray($validated);

            Log::info("Despachando imagens para o filme ID: {$movie->id}");
            ProcessMovieImagesJob::dispatch($movie, $imageUrls)->onQueue('images');

            // 4. Tradução (Se não tem descrição em PT, mas tem em EN)
            if (!filled($validated['descricao_br']) && filled($validated['descricao_en'])) {
                Log::info("Despachando tradução para o filme ID: {$movie->id}");
                ProcessMovieTranslationJob::dispatch($movie, $validated['descricao_en'])->onQueue('translation');
            }
        } else {
            Log::warning("Filme salvo incorretamente detectado no fluxo principal. Removendo da base. TMDB: {$this->data['tmdb_id']}");
            if ($movie) {
                $movie->delete();
            }
        }
    }

    private function createImageArray($dataValidated)
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

    /**
     * Acionado automaticamente quando o Job falha em definitivo (esgota as 3 tentativas)
     * ou encontra uma Exception fatal no meio do handle().
     */
    public function failed(\Throwable $exception)
    {
        Log::critical("Job falhou fatalmente para Filme TMDB {$this->data['tmdb_id']}. Removendo possíveis dados nulos: " . $exception->getMessage());

        $movie = Movie::where('tmdb_id', $this->data['tmdb_id'])->first();

        if ($movie) {
            // Se o título original sumiu, se está nulo, ou se o status ficou preso em processando, deleta.
            if ($movie->status !== 'processado' || is_null($movie->titulo_original) || empty($movie->titulo_original)) {
                Log::warning("Limpando registro fantasma incompleto do banco de dados. ID TMDB: {$this->data['tmdb_id']}");
                $movie->delete();
            }
        }
    }
}