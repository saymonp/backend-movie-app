<?php

namespace Tests\Feature;

use App\Jobs\ProcessMovieTranslationJob;
use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ProcessMovieTranslationJobTest::class)]
class ProcessMovieTranslationJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cenário 1: Caminho feliz. A API da Google responde com sucesso,
     * o texto é decodificado e a coluna 'descricao_br' do filme é atualizada.
     */
    public function test_deve_traduzir_texto_e_atualizar_descricao_do_filme_com_sucesso(): void
    {
        // 1. Cria o filme sem a descrição em português
        $movie = Movie::factory()->create([
            'descricao_br' => null,
            'descricao_en' => 'An insomniac office worker and a devil-may-care soap maker...'
        ]);

        // 2. Mocka a resposta da API de tradução do Google Cloud v2
        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => [
                    'translations' => [
                        [
                            'translatedText' => 'Um funcionário de escritório insone e um fabricante de sabão...'
                        ]
                    ]
                ]
            ], 200)
        ]);

        // 3. Instancia o Job passando a propriedade correta: $textoOriginal
        $job = new ProcessMovieTranslationJob($movie, $movie->descricao_en, 'pt');
        $job->handle();

        // 4. Verifica se a tradução foi persistida com sucesso no banco de dados
        $this->assertDatabaseHas('movies', [
            'id' => $movie->id,
            'descricao_br' => 'Um funcionário de escritório insone e um fabricante de sabão...'
        ]);
    }
}