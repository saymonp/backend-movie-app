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

class StoreMovieJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected array $data)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $movieResponse = $this->getMovieDetails($this->data['tmdb_id'], $this->data['poster_path_en'], $this->data['generos_en']);

        dump($movieResponse);

        $validator = Validator::make($movieResponse ?? [], [
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
            'poster_path_br'         => 'nullable|url',
            'poster_thumb_br'   => 'nullable|url',
            'backdrop_path'       => 'nullable|url',
            'poster_path_us'         => 'nullable|url',
            'poster_thumb_us'   => 'nullable|url',
            'generos'        => 'required|array',
            'diretores'           => 'nullable|array',
            'estudios'          => 'nullable|array',
            'paises'            => 'nullable|array',
            'colecao'           => 'nullable|array',
            'colecao.nome'      => 'required_with:colecao|string',
            'colecao.tmdb_id'   => 'required_with:colecao|integer',
            'colecao.poster_path' => 'nullable|string',
            'colecao.poster_thumb' => 'nullable|string',
            'colecao.backdrop_path' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            $this->failed(throw new \Exception($validator->errors()->first()));
            return;
        }

        $validated = $validator->validated();

        DB::transaction(function () use ($validated) {

            // 2. Criar o Filme
            $movie = Movie::create($validated);

            // 3. Processar Relacionamentos (Usando método auxiliar para evitar repetição)
            // TODO passar estes métodos para o Model
            $this->syncRelationsGeneros($movie, 'generos', \App\Models\Genero::class, $validated['generos']);
            $this->syncRelations($movie, 'diretores', \App\Models\Diretor::class, $validated['diretores']);
            $this->syncRelations($movie, 'estudios', \App\Models\Estudio::class, $validated['estudios']);
            $this->syncRelations($movie, 'paises', \App\Models\Pais::class, $validated['paises']);

            // 3. Despacha o processamento de imagens
            $imageUrls = $this->createImageArray($validated);
            // TODO ProcessMovieImagesJob::dispatch($movie, $imageUrls);
        });
    }


    private function syncRelations($movie, $relation, $modelClass, $names)
{
    if (empty($names)) return;

    $ids = collect($names)->map(function ($name) use ($modelClass) {
        // 🔄 A MÁGICA ESTÁ AQUI:
        // Procura por um registro com esse nome. 
        // Se não achar, cria. Se achar, retorna o existente.
        $record = $modelClass::firstOrCreate(['nome' => trim($name)]);
        
        return $record->id;
    });

    // Sincroniza os IDs na tabela pivô
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
    public function failed(\Throwable $exception)
    {
        Log::error("Erro ao criar filme TMDB ID {$this->data['tmdb_id']}: " . $exception->getMessage());
    }

    function createImageArray($dataValidated)
    {
        $imagesArray = [
            $dataValidated['poster_path_br'],
            $dataValidated['poster_thumb_br'],
            $dataValidated['backdrop_path'],
            $dataValidated['poster_path_us'],
            $dataValidated['poster_thumb_us'],
            'colecao' => [
                'poster_path' => isset($dataValidated['colecao']['poster_path']) ?? null,
                'poster_thumb' => isset($dataValidated['colecao']['poster_thumb']) ?? null,
                'backdrop_path' => isset($dataValidated['colecao']['backdrop_path']) ?? null
            ]
        ];
        return $imagesArray;
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

    function getMovieDetails($tmdb_id, $poster_path_en, $generos_en)
    {
        $tmdb_api_key = config('services.tmdb.key');

        $url = "https://api.themoviedb.org/3/movie/{$tmdb_id}?append_to_response=translations,images,credits&include_image_language=en,pt,null&language=pt-BR";

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$tmdb_api_key}",
            'accept' => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        $translations = $data['translations']['translations'] ?? [];

        // 🇧🇷 PT-BR
        $ptBR = collect($translations)->first(function ($t) {
            return $t['iso_639_1'] === 'pt' && $t['iso_3166_1'] === 'BR';
        });

        // 🇺🇸 EN-US
        $enUS = collect($translations)->first(function ($t) {
            return $t['iso_639_1'] === 'en' && $t['iso_3166_1'] === 'US';
        });

        // 🧠 descrição PT
        if (!empty($ptBR['data']['overview'])) {
            $descricao_pt = $ptBR['data']['overview'];
        } elseif (!empty($enUS['data']['overview'])) {
            // Aqui você pode integrar tradução depois
            $descricao_pt = $enUS['data']['overview'];
        } else {
            $descricao_pt = null;
        }

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
            'tmdb_id' => $data['id'],

            'titulo_original' => $data['original_title'] ?? null,

            // 🇧🇷
            'titulo_br' => $ptBR['data']['title'] ?? null,
            'descricao_br' => $descricao_pt,
            'tagline_br' => $data['tagline'] ?? null,

            // 🇺🇸
            'titulo_en' => $enUS['data']['title'] ?? $data['original_title'] ?? null,
            'descricao_en' => $enUS['data']['overview'] ?? $data['overview'] ?? null,
            'tagline_en' => $enUS['data']['tagline'] ?? null,

            'rating' => $data['vote_average'] ?? null,
            'duracao' => $data['runtime'] ?? null,

            //'generos_pt' => array_map(fn($g) => ['tmdb_id' => $g['id'], 'nome' => $g['name']], array_values($data['genres'])) ?? [],
            //'generos_en' => $generos_en ?? [],

            'generos' => collect($data['genres'])
                ->map(function ($itemPt) use ($generos_en) {
                    // Transformamos em collection para usar o firstWhere
                    $itemEn = collect($generos_en)->firstWhere('id', $itemPt['id']);

                    return [
                        'tmdb_id' => $itemPt['id'],
                        'nome_pt' => $itemPt['name'],
                        // Busca o nome no itemEn, se não achar (fallback), usa o nome_pt
                        'nome_en' => $itemEn['name'] ?? ($itemEn['nome'] ?? $itemPt['name']),
                    ];
                })->all(),

            'pais_origem' => isset($data['origin_country']) ? $data['origin_country'] : null,

            'lingua_origem' => $data['original_language'] ?? null,

            'estudios' => array_column($data['production_companies'], 'name') ?? [],
            // URLs temporárias do TMBb
            'poster_path_br' => isset($data['poster_path'])
                ? "https://image.tmdb.org/t/p/original{$data['poster_path']}"
                : null,
            'poster_thumb_br' => isset($data['poster_path'])
                ? "https://image.tmdb.org/t/p/w500{$data['poster_path']}"
                : null,

            'backdrop_path' => isset($data['backdrop_path'])
                ? "https://image.tmdb.org/t/p/w500{$data['backdrop_path']}"
                : null,

            'poster_path_us' => ($poster_path_en)
                ? "https://image.tmdb.org/t/p/original{$poster_path_en}"
                : null,
            'poster_thumb_us' => ($poster_path_en)
                ? "https://image.tmdb.org/t/p/original{$poster_path_en}"
                : null,

            'diretores' => collect($data['credits']['crew'] ?? [])
                ->where('job', 'Director')
                ->pluck('name')
                ->all(),

            'paises' => isset($data['production_countries'])
                ? array_column($data['production_countries'], 'name')
                : [],

            'homepage' => $data['homepage'] ?? null,

            'belongs_to_collection' => $data['belongs_to_collection'] ?? null,

            'release_date' => $data['release_date'] ?? null,

            'slug_pt' => $slug['slug_pt'],
            'slug_en' => $slug['slug_en'],

            'colecao' => filled($data['belongs_to_collection'] ?? null) ? [
                'tmdb_id'       => $data['belongs_to_collection']['id'] ?? null,
                'nome'          => $data['belongs_to_collection']['name'] ?? null,
                'poster_path'   => isset($data['belongs_to_collection']['poster_path'])
                    ? "https://image.tmdb.org/t/p/original{$data['belongs_to_collection']['poster_path']}"
                    : null,
                'poster_thumb'  => isset($data['belongs_to_collection']['poster_path'])
                    ? "https://image.tmdb.org/t/p/w500{$data['belongs_to_collection']['poster_path']}"
                    : null,
                'backdrop_path' => isset($data['belongs_to_collection']['backdrop_path'])
                    ? "https://image.tmdb.org/t/p/original{$data['belongs_to_collection']['backdrop_path']}"
                    : null,
            ] : null,
        ];
    }
}
