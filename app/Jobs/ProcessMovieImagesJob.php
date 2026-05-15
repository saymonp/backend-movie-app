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

    public function __construct(protected Movie $movie, protected array $imageUrls) {}

    public function handle(): void
    {
        Log::info("Iniciando processamento de imagens para o filme ID: {$this->movie->id}");

        $updates = [];

        // 1. Processar imagens principais do Filme -> DISCO: 's3'
        $mainMap = [
            'poster_path_br',
            'poster_thumb_br',
            'backdrop_path',
            'poster_path_us',
            'poster_thumb_us'
        ];

        foreach ($mainMap as $key) {
            if (!empty($this->imageUrls[$key])) {
                // Passamos 's3' como o disco para os posters
                $path = $this->downloadAndStore($this->imageUrls[$key], 'posters', 's3');
                if ($path) $updates[$key] = $path;
            }
        }

        // 2. Processar imagens da Coleção -> DISCO: 's3_collections'
        if (!empty($this->imageUrls['colecao']) && $this->movie->colecao) {
            $colecaoUpdates = [];
            $colMap = ['poster_path', 'poster_thumb', 'backdrop_path'];

            foreach ($colMap as $colKey) {
                if (!empty($this->imageUrls['colecao'][$colKey])) {
                    // Passamos 's3_collections' como o disco para coleções
                    $path = $this->downloadAndStore($this->imageUrls['colecao'][$colKey], 'collections', 's3_collections');
                    if ($path) $colecaoUpdates[$colKey] = $path;
                }
            }

            if (!empty($colecaoUpdates)) {
                $this->movie->colecao->update($colecaoUpdates);
            }
        }

        if (!empty($updates)) {
            $this->movie->update($updates);
        }
    }

    /**
     * Adicionado o parâmetro $disk para alternar entre os buckets
     */
    private function downloadAndStore($url, $folder, $disk)
    {
        $path = ltrim(str_replace('\\', '', $url), '/');
        if (empty($path)) return null;

        $fullUrl = str_starts_with($path, 'http')
            ? $path
            : "https://image.tmdb.org/t/p/original/{$path}";

        try {
            $response = Http::withoutVerifying()->timeout(45)->get($fullUrl);

            if ($response->successful()) {
                $extension = pathinfo(parse_url($fullUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = "{$folder}/" . Str::uuid() . "." . $extension;

                // Usamos o disco dinâmico definido na chamada do método
                Storage::disk($disk)->put($filename, $response->body(), [
                    'visibility' => 'public',
                    'ContentType' => $response->header('Content-Type')
                ]);

                // Retorna a URL final do arquivo no S3 para salvar no banco
                /** @disregard P1013 Undefined method */
                return Storage::disk($disk)->url($filename);
            }
        } catch (\Exception $e) {
            Log::error("Erro no download da imagem {$fullUrl}: " . $e->getMessage());
        }

        return $fullUrl;
    }

    public function failed(\Throwable $exception)
    {
        Log::critical("Falha total no Job de imagens do Filme {$this->movie->id}: {$exception->getMessage()}");
    }
}
