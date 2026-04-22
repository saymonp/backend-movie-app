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
        DB::transaction(function () {
            // 1. Criação do filme (com URLs originais temporárias)
            $movie = Movie::create($this->data);

            // 2. Sincroniza relações (Gêneros, etc.)
            // Use o método auxiliar que criamos anteriormente

            // 3. Despacha o processamento de imagens
            ProcessMovieImagesJob::dispatch($movie, $this->data);
        });
    }

    public function failed(\Throwable $exception)
    {
        Log::error("Erro ao criar filme TMDB ID {$this->data['tmdb_id']}: " . $exception->getMessage());
    }
}
