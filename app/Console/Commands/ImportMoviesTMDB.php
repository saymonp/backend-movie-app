<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use App\Jobs\StoreMovieJob;
use App\Models\Movie;
use Illuminate\Support\Facades\Log;
use App\Services\TmdbService;


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
        
        $tmdb = new TmdbService();

        $limit = $this->argument('limit');
        $pages = max(1, round($limit / 20)); // Garante pelo menos 1 página

        $this->info("Iniciando busca de filmes no TMDB. Páginas: {$pages}");

        $jobs = []; // Vamos guardar todos os jobs aqui antes de disparar o lote

        for ($page = 1; $page <= $pages; $page++) {
            $this->comment("Buscando página {$page}...");

            $movies = $tmdb->getMoviePages($page);

            foreach ($movies as $movie) {
                if (Movie::where('tmdb_id', $movie['id'])->doesntExist()) {

                    $jobs[] = new StoreMovieJob([
                        'tmdb_id' => $movie['id'],
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
