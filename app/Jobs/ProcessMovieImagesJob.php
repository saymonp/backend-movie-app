<?php

namespace App\Jobs;

use App\Models\Movie;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessMovieImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    public function __construct(protected Movie $movie, protected array $imageUrls)
    {
    }

    public function handle(): void
    {
        Log::info("Iniciando processamento de imagens para o filme: {$this->movie->titulo_br} (ID: {$this->movie->id})");

        $updates = [];

        // Mapeamos as chaves que vem do Job para as colunas do seu banco
        $map = [
            'poster_path_br'  => 'poster_path_br',
            'poster_thumb_br' => 'poster_thumb_br',
            'backdrop_path'   => 'backdrop_path',
            'poster_path_us'  => 'poster_path_us',
            'poster_thumb_us' => 'poster_thumb_us',
        ];

        foreach ($map as $key => $column) {
            if (empty($this->imageUrls[$key])) continue;

            $url = $this->imageUrls[$key];
            
            try {
                $path = $this->downloadAndStore($url);
                if ($path) {
                    $updates[$column] = $path;
                }
            } catch (\Exception $e) {
                Log::error("Falha ao processar imagem {$key} para o filme {$this->movie->id}: " . $e->getMessage());
                // Lançamos a exceção para o Job tentar novamente (retry)
                throw $e;
            }
        }

        // Se houver atualizações, salva no banco de uma vez
        if (!empty($updates)) {
            $this->movie->update($updates);
            Log::info("Imagens do filme {$this->movie->id} atualizadas com sucesso.");
        }
    }

    /**
     * Auxiliar para baixar e salvar
     */
    private function downloadAndStore($url)
    {
        $response = Http::timeout(30)->get($url);

        if ($response->successful()) {
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $filename = "posters/" . Str::uuid() . "." . $extension;
            
            // Salva no S3
            Storage::disk('s3')->put($filename, $response->body(), 'public');
            
            return $filename;
        }

        return null;
    }

    public function failed(\Throwable $exception)
    {
        Log::critical("ERRO FINAL: Imagens do filme ID {$this->movie->id} falharam após 3 tentativas. Motivo: {$exception->getMessage()}");
    }
}