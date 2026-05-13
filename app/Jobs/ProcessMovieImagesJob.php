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

        // 1. Processar imagens principais do Filme
        $mainMap = [
            'poster_path_br',
            'poster_thumb_br',
            'backdrop_path',
            'poster_path_us',
            'poster_thumb_us'
        ];

        foreach ($mainMap as $key) {
            if (!empty($this->imageUrls[$key])) {
                $path = $this->downloadAndStore($this->imageUrls[$key], 'posters');
                if ($path) $updates[$key] = $path;
            }
        }

        // 2. Processar imagens da Coleção (se existir no array de entrada e no filme)
        if (!empty($this->imageUrls['colecao']) && $this->movie->colecao) {
            $colecaoUpdates = [];
            $colMap = ['poster_path', 'poster_thumb', 'backdrop_path'];

            foreach ($colMap as $colKey) {
                if (!empty($this->imageUrls['colecao'][$colKey])) {
                    $path = $this->downloadAndStore($this->imageUrls['colecao'][$colKey], 'collections');
                    if ($path) $colecaoUpdates[$colKey] = $path;
                }
            }

            if (!empty($colecaoUpdates)) {
                // Atualiza o modelo de coleção relacionado
                $this->movie->colecao->update($colecaoUpdates);
            }
        }

        // Atualiza os campos do filme
        if (!empty($updates)) {
            $this->movie->update($updates);
        }
    }

    private function downloadAndStore($url, $folder)
    {
        // Limpeza de caracteres e barras
        $path = ltrim(str_replace('\\', '', $url), '/');
        if (empty($path)) return null;

        $fullUrl = str_starts_with($path, 'http')
            ? $path
            : "https://image.tmdb.org/t/p/original/{$path}";

        try {
            $response = Http::withoutVerifying()->timeout(45)->get($fullUrl);

            if ($response->successful()) {
                $extension = pathinfo(parse_url($fullUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = "{$folder}/" . \Illuminate\Support\Str::uuid() . "." . $extension;

                Storage::disk('s3')->put($filename, $response->body(), [
                    'visibility' => 'public',
                    'ContentType' => $response->header('Content-Type')
                ]);

                return $filename; // Retorna o caminho do seu S3
            }
        } catch (\Exception $e) {
            Log::error("Erro no download da imagem {$fullUrl}: " . $e->getMessage());
        }

        // EM CASO DE ERRO: Retorna a URL original do TMDB formatada
        return $fullUrl;
    }

    public function failed(\Throwable $exception)
    {
        Log::critical("Falha total no Job de imagens do Filme {$this->movie->id}: {$exception->getMessage()}");
    }
}
