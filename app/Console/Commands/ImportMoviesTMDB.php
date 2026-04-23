<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use App\Jobs\StoreMovieJob;
use App\Models\Movie;

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

        // 1. Requisição ao TMDB (Exemplo pegando os mais populares)
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$tmdb_api_key}",
            'accept' => 'application/json',
        ])->get('https://api.themoviedb.org/3/movie/popular?&page=');

        if ($response->failed()) {
            $this->error('Falha ao conectar com o TMDB');
            return;
        }

        $movies = $response->json()['results'];
        // 2. Criar o Batch (Lote)
        $batch = Bus::batch([])->name('Importação Diária de Filas')->dispatch();
        $index = 0;
        foreach ($movies as $movie) {
            // Verifica se o filme já existe
            if (Movie::where('tmdb_id', $movie['id'])->doesntExist()) {
                $movieData = [
                    'tmdb_id' => $movie['id'],
                    'poster_path_en' => $movie['poster_path'],
                ];
                // Adiciona cada filme como um Job no lote
                $batch->add(new StoreMovieJob($movieData));
                $index++;
            }
        }

        $this->info("Sucesso! {$index} filmes não repetidos foram enviados para a fila.");
        $this->info("Use 'php artisan queue:work' para começar o processamento.");
    }
}
