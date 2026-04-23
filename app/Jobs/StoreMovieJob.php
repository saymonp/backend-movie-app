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

class StoreMovieJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $movieResponse = $this->getMovieDetails($this->data['tmdb_id'], $this->data['poster_path'], $this->data['generos_en']);

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
            'poster_br'         => 'required|url',
            'poster_br_thumb'   => 'required|url',
            'backdrop_br'       => 'required|url',
            'poster_en'         => 'required|url',
            'poster_en_thumb'   => 'required|url',
            'generos_pt'        => 'required|array',
            'generos_en'        => 'required|array',
            'diretor'           => 'required|array',
            'estudios'          => 'required|array',
            'paises'            => 'required|array',
            'colecao'           => 'nullable|array',
            'colecao.name'      => 'required_with:colecao|string',
            'colecao.tmdb_id'   => 'required_with:colecao|integer',
            'colecao.poster_path' => 'required_with:colecao|string',
            'colecao.poster_thumb' => 'required_with:colecao|string',
            'colecao.backdrop_path' => 'required_with:colecao|string'
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
            $movie->syncRelations($movie, 'generos', \App\Models\Genero::class, $validated['generos_pt']);
            $movie->syncRelations($movie, 'generos', \App\Models\Genero::class, $validated['generos_en']);
            $movie->syncRelations($movie, 'diretores', \App\Models\Diretor::class, $validated['diretor']);
            $movie->syncRelations($movie, 'estudios', \App\Models\Estudio::class, $validated['estudios']);
            $movie->syncRelations($movie, 'paises', \App\Models\Pais::class, $validated['paises']);
            
            // 3. Despacha o processamento de imagens
            $imageUrls = $this->createImageArray($validated);
            ProcessMovieImagesJob::dispatch($movie, $imageUrls);
        });
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
            $dataValidated['backdrop_path_br'],
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

    function createSlug($titulo_br, $titulo_en, $data)
    {
        $slug_pt = $titulo_br ? Str::slug($titulo_br) : null;
        $slug_en = $titulo_en ? Str::slug($titulo_en) : null;

        // Verifica se já existe no banco
        $exists = Movie::where('slug_en', $slug_en)->exists();

        if (!$exists) {
            return [
                'slug_pt' => $slug_pt,
                'slug_en' => $slug_en,
            ];
        }

        // Se já existe, adiciona ano ou número aleatório
        $year = $data ? explode('-', $data)[0] : rand(1, 999);

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
        $slug = $this->createSlug(
            $ptBR['data']['title'] ?? null,
            $enUS['data']['title'] ?? $data['original_title'] ?? null,
            $data['release_date'] ?? null
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

            'generos_pt' => array_column($data['genres'], 'name') ?? [],
            'generos_en' => $generos_en ?? [],

            'pais_origem' => isset($data['origin_country']) ? $data['origin_country'] : null,

            'lingua_origem' => $data['original_language'] ?? null,

            'estudio' => array_column($data['production_companies'], 'name') ?? [],
            // URLs temporárias do TMBb
            'poster_path_br' => isset($data['poster_path'])
                ? "https://image.tmdb.org/t/p/original{$data['poster_path']}"
                : null,
            'poster_thumb_br' => isset($data['poster_path'])
                ? "https://image.tmdb.org/t/p/w500{$data['poster_path']}"
                : null,

            'backdrop_path_br' => isset($data['backdrop_path'])
                ? "https://image.tmdb.org/t/p/w500{$data['backdrop_path']}"
                : null,

            'poster_path_us' => ($poster_path_en)
                ? "https://image.tmdb.org/t/p/original{$poster_path_en}"
                : null,
            'poster_thumb_us' => ($poster_path_en)
                ? "https://image.tmdb.org/t/p/original{$poster_path_en}"
                : null,

            'diretor' => collect($data['credits']['crew'] ?? [])
                ->where('job', 'Director')
                ->pluck('name')
                ->all(),

            'homepage' => $data['homepage'] ?? null,

            'belongs_to_collection' => $data['belongs_to_collection'] ?? null,

            'release_date' => $data['release_date'] ?? null,

            'slug_pt' => $slug['slug_pt'],
            'slug_en' => $slug['slug_en'],

            'colecao' => [
                'tmdb_id' => isset($data['colecao']['id']) ? $data['colecao']['poster_path'] : null,
                'nome' => isset($data['colecao']['name']) ? $data['colecao']['poster_path'] : null,
                'poster_path' => isset($data['colecao']['poster_path']) ? $data['colecao']['poster_path'] : null,
                'poster_thumb' => isset($data['colecao']['poster_thumb']) ? $data['colecao']['poster_path'] : null,
                'backdrop_path' => isset($data['colecao']['backdrop_path']) ? $data['colecao']['backdrop_path'] : null
            ]
        ];
    }
}
