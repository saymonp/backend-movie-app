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
        // Se a URL for apenas um path (ex: /abc.jpg), precisamos do prefixo do TMDB
        // Se você já envia a URL completa no Service, pode ignorar esta linha
        $fullUrl = str_starts_with($url, 'http') ? $url : "https://image.tmdb.org/t/p/original{$url}";

        try {
            $response = Http::timeout(30)->get($fullUrl);

            if ($response->successful()) {
                $extension = pathinfo(parse_url($fullUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = "{$folder}/" . Str::uuid() . "." . $extension;

                // Correção de Visibilidade para S3
                Storage::disk('s3')->put($filename, $response->body(), [
                    'visibility' => 'public'
                ]);

                return $filename;
            }
        } catch (\Exception $e) {
            Log::error("Erro no download da imagem {$fullUrl}: " . $e->getMessage());
        }

        return null;
    }

    public function failed(\Throwable $exception)
    {
        Log::critical("Falha total no Job de imagens do Filme {$this->movie->id}: {$exception->getMessage()}");
    }
}
