<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use App\Models\Movie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Validator;

class ProcessMovieImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithQueue;

    // Tenta 3 vezes antes de marcar como falha definitiva
    public $tries = 3;

    // Aguarda 60 segundos entre as tentativas
    public $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(protected Movie $movie, protected array $imageUrls)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $validator = Validator::make($this->imageUrls ?? [], [
            'poster_path_br'    => 'nullable|url',
            'poster_thumb_br'   => 'nullable|url',
            'backdrop_path'     => 'nullable|url',
            'poster_path_us'    => 'nullable|url',
            'poster_thumb_us'   => 'nullable|url',

            'colecao'           => 'nullable|array',
            'colecao.nome'      => 'required_with:colecao|string',
            'colecao.tmdb_id'   => 'required_with:colecao|integer',
            'colecao.poster_path' => 'nullable|string',
            'colecao.poster_thumb' => 'nullable|string',
            'colecao.backdrop_path' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            $this->failed(throw new \Exception($validator->errors()->first()));
            return;
        }

        $validated = $validator->validated();

        $updates = [];

        foreach ($validated as $reqKey => $dbCol) {
            $url = $this->imageUrls[$reqKey];
            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                $path = "posters/" . Str::uuid() . ".jpg";
                Storage::disk('s3')->put($path, $response->body(), 'public');
                $updates[$dbCol] = $path;
            } else {
                // Se falhar o download, lançamos uma exceção para a fila tentar novamente
                throw new \Exception("Falha ao baixar imagem: {$url}");
            }
        }

        $this->movie->update($updates);
    }

    public function failed(\Throwable $exception)
    {
        // Aqui reportamos o erro final no log após as 3 tentativas
        Log::critical("ERRO CRÍTICO: Imagens do filme ID {$this->movie->id} não puderam ser salvas após todas as tentativas. {$exception}");
    }
}
