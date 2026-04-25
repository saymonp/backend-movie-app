<?php

namespace App\Jobs;

use App\Models\Movie;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessMovieTranslationJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = 300;
    /**
     * Create a new job instance.
     */
    public function __construct(protected Movie $movie, protected string $textoOriginal, protected string $target = 'pt')
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $textoTraduzido = $this->traduzir($this->textoOriginal, 'pt');

        if ($textoTraduzido) {
            $this->movie->update([
                'descricao_br' => html_entity_decode($textoTraduzido, ENT_QUOTES, 'UTF-8')
            ]);

            Log::info("Tradução concluída para o filme: {$this->movie->id}");
        }
    }

    protected function traduzir($texto, $target)
    {
        // TODO Criar ServiceTranslate
        try {
            $apiKey = config('services.google.translation_key'); // Ou env('GOOGLE_API_KEY')

            $response = Http::post('https://translation.googleapis.com/language/translate/v2', [
                // A Google API aceita os parâmetros no corpo ou na query
                'q' => $texto,
                'target' => $target,
                'format' => 'text', // 'text' ou 'html'
            ], [
                'key' => $apiKey
            ]);

            if ($response->successful()) {
                $response->json('data.translations.0.translatedText');
            }

            Log::error('Erro na resposta da tradução: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Erro ao traduzir: ' . $e->getMessage());
        }
    }
}
