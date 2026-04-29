<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\Movie;

class TmdbService
{
    /**
     * Create a new class instance.
     */
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.tmdb.key');
    }

    public function getMovieDetails($tmdb_id, $generos_ids)
    {
        // CACHE: Guarda a resposta por 24 horas para evitar re-fazer a requisição em caso de erro no banco
        return Cache::remember("tmdb_details_{$tmdb_id}", now()->addDay(), function () use ($tmdb_id, $generos_ids) {

            $tmdb_api_key = config('services.tmdb.key');
            $url = "https://api.themoviedb.org/3/movie/{$tmdb_id}?append_to_response=translations,images,credits&include_image_language=en,pt,null&language=pt-BR";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$tmdb_api_key}",
                'accept' => 'application/json',
            ])->get($url);

            if ($response->failed()) {
                // não cacheia erro
                throw new \Exception('Erro ao buscar gêneros TMDB');
            }

            $data = $response->json();
            $translations = $data['translations']['translations'] ?? [];

            $ptBR = collect($translations)->firstWhere('iso_639_1', 'pt');
            $enUS = collect($translations)->firstWhere('iso_639_1', 'en');

            $posters = collect($data['images']['posters'] ?? []);

            $poster_path_en = $posters->firstWhere('iso_639_1', 'en')['file_path']
                ?? $posters->firstWhere('iso_639_1', null)['file_path']
                ?? $posters->first()['file_path']
                ?? null;

            $generos_en = collect($data['genres'])
                ->whereIn('id', $generos_ids)
                ->map(fn($g) => ['id' => $g['id'], 'name' => $g['name']])
                ->toArray();


            // 🎯 Slug
            $slug = $this->createSlug(
                // Tenta pegar o título PT-BR, se não existir, usa o título padrão da resposta
                filled($ptBR['data']['title'] ?? null) ? $ptBR['data']['title'] : ($data['title'] ?? null),

                // Tenta pegar o título EN-US, se não existir, usa o original_title
                filled($enUS['data']['title'] ?? null) ? $enUS['data']['title'] : ($data['original_title'] ?? $data['title']),

                $data['release_date'] ?? null
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

    public function getMovieGeneros()
    {
        return Cache::remember('tmdb_genres_en_us', now()->addWeek(), function () {

            $response = Http::withToken($this->token)
                ->get('https://api.themoviedb.org/3/genre/movie/list?language=en-US');

            if ($response->failed()) {
                // não cacheia erro
                throw new \Exception('Erro ao buscar gêneros TMDB');
            }

            return $response->json()['genres'] ?? [];
        });
    }

    public function getMoviePages($page)
    {

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'accept' => 'application/json',
        ])->get("https://api.themoviedb.org/3/movie/popular?&page={$page}");

        if ($response->failed()) {
            throw new \Exception('Erro ao buscar gêneros TMDB');
        }

        $movies = $response->json()['results'] ?? [];

        return $movies;
    }
}
