<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use App\Jobs\StoreMovieJob;
use App\Models\Movie;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;


class ImportMoviesTMDB extends Command
{
    // O nome que você usará no terminal
    protected $signature = 'import:movies {limit=20}';
    protected $description = 'Busca filmes no TMDB e coloca na fila de importação';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = $this->argument('limit');
        $pages = max(1, round($limit / 20)); // Garante pelo menos 1 página
        $tmdb_api_key = config('services.tmdb.key');

        $this->info("Iniciando busca de filmes no TMDB. Páginas: {$pages}");

        // Cache de gêneros (Perfeito!)
        $genresEn = Cache::remember('tmdb_genres_en', now()->addWeek(), function () use ($tmdb_api_key) {
            $response = Http::withToken($tmdb_api_key)
                ->get('https://api.themoviedb.org/3/genre/movie/list?language=en-US');
            return $response->json()['genres'] ?? [];
        });

        $jobs = []; // Vamos guardar todos os jobs aqui antes de disparar o lote

        for ($page = 1; $page <= $pages; $page++) {
            $this->comment("Buscando página {$page}...");

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$tmdb_api_key}",
                'accept' => 'application/json',
            ])->get("https://api.themoviedb.org/3/movie/popular?&page={$page}");

            if ($response->failed()) continue;

            $movies = $response->json()['results'] ?? [];

            foreach ($movies as $movie) {
                if (Movie::where('tmdb_id', $movie['id'])->doesntExist()) {
                    $movieGenresEn = collect($genresEn)
                        ->whereIn('id', $movie['genre_ids'])
                        ->map(fn($g) => ['id' => $g['id'], 'name' => $g['name']])
                        ->toArray();

                    $jobs[] = new StoreMovieJob([
                        'tmdb_id' => $movie['id'],
                        'poster_path_en' => $movie['poster_path'],
                        'generos_en' => $movieGenresEn
                    ]);
                }
            }
        }

        if (count($jobs) > 0) {
            // CRIA UM ÚNICO LOTE PARA TUDO
            Bus::batch($jobs)
                ->name("Importação Massiva TMDB - " . now()->format('d/m/Y'))
                ->then(function ($batch) {
                    // Esse Log só aparece quando os 20, 40 ou 100 filmes terminarem
                    Log::info("✅ Importação de Filmes Concluída! Fila de imagens liberada.");
                })
                ->catch(function ($batch, $e) {
                    Log::error("❌ Erro no lote de importação: " . $e->getMessage());
                })
                ->dispatch();

            $this->info("Sucesso! " . count($jobs) . " filmes enviados para a fila.");
        } else {
            $this->warn("Nenhum filme novo encontrado para importar.");
        }
    }
}
