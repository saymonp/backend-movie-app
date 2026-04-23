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

#[Signature('app:import-movies-t-m-d-b')]
#[Description('Command description')]
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
        $pages = round($limit / 20);
        $total = $pages * 20;
        $tmdb_api_key = config('services.tmdb.key');

        $this->info("Iniciando busca de {$total} filmes no TMDB. Páginas: {$pages}");

        // Pega lista de IDs de genêros para obter os gêneros em inglês.
        // Cache 24hx7
        $genresEn = Cache::remember('tmdb_genres_en', 604.800, function () {
            return Http::withToken(config('services.tmdb.key'))
                ->get('https://api.themoviedb.org/3/genre/movie/list?language=en-US')
                ->json()['genres'];
        });

        // 1. Requisição ao TMDB (Exemplo pegando os mais populares)
        for ($page = 1; $page <= $pages; $page++) {
            $this->comment("Buscando página {$page}...");
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$tmdb_api_key}",
                'accept' => 'application/json',
            ])->get("https://api.themoviedb.org/3/movie/popular?&page={$page}");

            if ($response->failed()) {
                $this->error('Falha ao conectar com o TMDB');
                return;
            }

            $movies = $response->json()['results'];
            // 2. Criar o Batch (Lote)
            $batch = Bus::batch([])->name("Importação Diária de Filas, página {$page} de {$pages}")->dispatch();
            $index = 0;
            foreach ($movies as $movie) {
                // Verifica se o filme já existe
                if (Movie::where('tmdb_id', $movie['id'])->doesntExist()) {
                    $movieData = [
                        'tmdb_id' => $movie['id'],
                        'poster_path_en' => $movie['poster_path'],
                        'generos_en' => array_values(array_intersect_key(array_column($genresEn['genres'], 'name', 'id'), array_flip($movie['genre_ids'])))

                    ];
                    // Adiciona cada filme como um Job no lote
                    $batch->add(new StoreMovieJob($movieData));
                    $index++;
                }
            }
        }
        $this->info("Sucesso! {$index} filmes inéditos foram enviados para a fila.");
        $this->info("Use 'php artisan queue:work' para começar o processamento.");
    }
}
