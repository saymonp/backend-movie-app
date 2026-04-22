<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use App\Jobs\StoreMovieJob;

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
        $this->info("Iniciando busca de {$limit} filmes no TMDB...");

        // 1. Requisição ao TMDB (Exemplo pegando os mais populares)
        $response = Http::get('https://api.themoviedb.org/3/movie/popular', [
            'api_key' => config('services.tmdb.key'),
            'language' => 'pt-BR',
        ]);

        if ($response->failed()) {
            $this->error('Falha ao conectar com o TMDB');
            return;
        }

        $movies = collect($response->json()['results'])->take($limit);

        // 2. Criar o Batch (Lote)
        $batch = Bus::batch([])->name('Importação Diária de Filas')->dispatch();

        foreach ($movies as $movieData) {
            // Adiciona cada filme como um Job no lote
            $batch->add(new StoreMovieJob($movieData));
        }

        $this->info("Sucesso! {$movies->count()} filmes foram enviados para a fila.");
        $this->info("Use 'php artisan queue:work' para começar o processamento.");
    }
}
