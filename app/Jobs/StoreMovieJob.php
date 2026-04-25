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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Cache;

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
        // 1. Obter detalhes (com Cache)
        $movieResponse = $this->getMovieDetails($this->data['tmdb_id'], $this->data['poster_path_en'], $this->data['generos_en']);

        if (!$movieResponse) {
            return;
        }
        //Log::info('Movie response:', $movieResponse);

        // 2. Validação
        $validator = Validator::make($movieResponse, [
            'tmdb_id'           => 'required|integer|unique:movies,tmdb_id',
            'titulo_original'   => 'required|string',
            'titulo_br'         => 'nullable|string',
            'titulo_en'         => 'nullable|string',
            'descricao_br'      => 'nullable|string',
            'descricao_en'      => 'required|string',
            'tagline_br'        => 'nullable|string',
            'tagline_en'        => 'nullable|string',
            'slug_pt'           => 'nullable|string|unique:movies,slug_pt',
            'slug_en'           => 'required|string|unique:movies,slug_en',
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
            'generos'           => 'required|array',
            'diretores'         => 'nullable|array',
            'estudios'          => 'nullable|array',
            'paises'            => 'nullable|array',
            'colecao'           => 'nullable|array',
            'colecao.nome'      => 'required_with:colecao|string',
            'colecao.tmdb_id'   => 'required_with:colecao|integer',
        ]);

        if ($validator->fails()) {
            Log::warning("Validação falhou para filme TMDB {$this->data['tmdb_id']}: " . $validator->errors()->first());
            return;
        }

        $validated = $validator->validated();

        // 3. Persistência
        $movie = DB::transaction(function () use ($validated) {
            $movieCreated = Movie::create($validated);

            $this->syncRelationsGeneros($movieCreated, 'generos', \App\Models\Genero::class, $validated['generos']);
            $this->syncRelations($movieCreated, 'diretores', \App\Models\Diretor::class, $validated['diretores'] ?? []);
            $this->syncRelations($movieCreated, 'estudios', \App\Models\Estudio::class, $validated['estudios'] ?? []);
            $this->syncRelations($movieCreated, 'paises', \App\Models\Pais::class, $validated['paises'] ?? []);

            return $movieCreated;
        });

        Log::info('Movie criado?', ['movie' => $movie]);
        if ($movie) {
            $imageUrls = $this->createImageArray($validated);

            Log::info("Despachando imagens para o filme ID: {$movie->id}");

            ProcessMovieImagesJob::dispatch($movie, $imageUrls)->onQueue('images');
        }
    }

    private function syncRelations($movie, $relation, $modelClass, $names)
    {
        if (empty($names)) return;

        $ids = collect($names)->map(function ($name) use ($modelClass) {
            $record = $modelClass::firstOrCreate(['nome' => trim($name)]);
            return $record->id;
        });

        $movie->$relation()->sync($ids);
    }

    private function syncRelationsGeneros($model, $relation, $relatedModel, $items)
    {
        if (empty($items)) return;

        $ids = collect($items)->map(function ($item) use ($relatedModel) {
            $registro = $relatedModel::updateOrCreate(
                ['tmdb_id' => $item['tmdb_id']],
                [
                    'nome_pt' => $item['nome_pt'],
                    'nome_en' => $item['nome_en'],
                ]
            );
            return $registro->id;
        })->all();

        $model->$relation()->sync($ids);
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

    function createSlug($titulo_br, $titulo_en, $releaseDate)
    {
        // Normaliza (null, vazio, espaços)
        $titulo_br = is_string($titulo_br) && trim($titulo_br) !== '' ? $titulo_br : null;
        $titulo_en = is_string($titulo_en) && trim($titulo_en) !== '' ? $titulo_en : null;

        $slug_pt = $titulo_br ? Str::slug($titulo_br) : null;
        $slug_en = $titulo_en ? Str::slug($titulo_en) : null;

        // Se não tem slug_en, não faz sentido continuar
        if (!$slug_en) {
            return [
                'slug_pt' => $slug_pt,
                'slug_en' => null,
            ];
        }

        // Verifica se já existe no banco
        $exists = Movie::where('slug_en', $slug_en)->exists();

        if (!$exists) {
            return [
                'slug_pt' => $slug_pt,
                'slug_en' => $slug_en,
            ];
        }

        // Ano (mais seguro)
        $year = null;
        if (!empty($releaseDate) && str_contains($releaseDate, '-')) {
            $year = explode('-', $releaseDate)[0];
        }

        $year = $year ?: rand(1, 999);

        return [
            'slug_pt' => $slug_pt ? "{$slug_pt}-{$year}" : null,
            'slug_en' => "{$slug_en}-{$year}",
        ];
    }

    public function getMovieDetails($tmdb_id, $poster_path_en, $generos_en)
    {
        // CACHE: Guarda a resposta por 24 horas para evitar re-fazer a requisição em caso de erro no banco
        return Cache::remember("tmdb_details_{$tmdb_id}", now()->addDay(), function () use ($tmdb_id, $poster_path_en, $generos_en) {

            $tmdb_api_key = config('services.tmdb.key');
            $url = "https://api.themoviedb.org/3/movie/{$tmdb_id}?append_to_response=translations,images,credits&include_image_language=en,pt,null&language=pt-BR";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$tmdb_api_key}",
                'accept' => 'application/json',
            ])->get($url);

            if ($response->failed()) return null;

            $data = $response->json();
            $translations = $data['translations']['translations'] ?? [];

            $ptBR = collect($translations)->firstWhere('iso_639_1', 'pt');
            $enUS = collect($translations)->firstWhere('iso_639_1', 'en');

            // 🎯 Slug
            // 🎯 Slug
            $slug = $this->createSlug(
                // Tenta pegar o título PT-BR, se não existir, usa o título padrão da resposta
                filled($ptBR['data']['title'] ?? null) ? $ptBR['data']['title'] : ($data['title'] ?? null),

                // Tenta pegar o título EN-US, se não existir, usa o original_title
                filled($enUS['data']['title'] ?? null) ? $enUS['data']['title'] : ($data['original_title'] ?? $data['title']),

                $data['release_date'] ?? null,
                $data['id']
            );

            return [
                'tmdb_id'         => $data['id'],
                'titulo_original' => $data['original_title'] ?? null,
                'titulo_br'       => $ptBR['data']['title'] ?? $data['title'] ?? null,
                'descricao_br'    => $ptBR['data']['overview'] ?? $data['overview'] ?? null,
                'tagline_br'      => $ptBR['data']['tagline'] ?? $data['tagline'] ?? null,
                'titulo_en'       => $enUS['data']['title'] ?? $data['original_title'] ?? null,
                'descricao_en'    => $enUS['data']['overview'] ?? $data['overview'] ?? null,
                'tagline_en'      => $enUS['data']['tagline'] ?? null,
                'rating'          => $data['vote_average'] ?? 0,
                'duracao'         => $data['runtime'] ?? 0,
                'lingua_origem'   => $data['original_language'] ?? 'en',
                'release_date'    => $data['release_date'] ?? null,
                'homepage'        => $data['homepage'] ?? null,
                'slug_pt' => $slug['slug_pt'],
                'slug_en' => $slug['slug_en'],

                'generos' => collect($data['genres'])->map(function ($itemPt) use ($generos_en) {
                    $itemEn = collect($generos_en)->firstWhere('id', $itemPt['id']);
                    return [
                        'tmdb_id' => $itemPt['id'],
                        'nome_pt' => $itemPt['name'],
                        'nome_en' => $itemEn['name'] ?? $itemPt['name'],
                    ];
                })->all(),

                'estudios' => array_column($data['production_companies'] ?? [], 'name'),
                'paises'   => array_column($data['production_countries'] ?? [], 'name'),
                'diretores' => collect($data['credits']['crew'] ?? [])->where('job', 'Director')->pluck('name')->unique()->all(),

                'poster_path_br'  => isset($data['poster_path']) ? "https://image.tmdb.org/t/p/original{$data['poster_path']}" : null,
                'poster_thumb_br' => isset($data['poster_path']) ? "https://image.tmdb.org/t/p/w500{$data['poster_path']}" : null,
                'backdrop_path'   => isset($data['backdrop_path']) ? "https://image.tmdb.org/t/p/original{$data['backdrop_path']}" : null,
                'poster_path_us'  => $poster_path_en ? "https://image.tmdb.org/t/p/original{$poster_path_en}" : null,
                'poster_thumb_us' => $poster_path_en ? "https://image.tmdb.org/t/p/w500{$poster_path_en}" : null,

                'colecao' => filled($data['belongs_to_collection'] ?? null) ? [
                    'tmdb_id'       => $data['belongs_to_collection']['id'],
                    'nome'          => $data['belongs_to_collection']['name'],
                    'poster_path'   => isset($data['belongs_to_collection']['poster_path']) ? "https://image.tmdb.org/t/p/original{$data['belongs_to_collection']['poster_path']}" : null,
                    'poster_thumb'  => isset($data['belongs_to_collection']['poster_path']) ? "https://image.tmdb.org/t/p/w500{$data['belongs_to_collection']['poster_path']}" : null,
                    'backdrop_path' => isset($data['belongs_to_collection']['backdrop_path']) ? "https://image.tmdb.org/t/p/original{$data['belongs_to_collection']['backdrop_path']}" : null,
                ] : null,
            ];
        });
    }

    public function failed(\Throwable $exception)
    {
        Log::error("Job falhou fatalmente para Filme TMDB {$this->data['tmdb_id']}: " . $exception->getMessage());
    }
}
