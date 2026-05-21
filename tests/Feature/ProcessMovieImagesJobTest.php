<?php

namespace Tests\Feature;

use App\Jobs\ProcessMovieImagesJob;
use App\Models\Colecao; // Ajuste se o nome da sua model de coleção for diferente
use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ProcessMovieImagesJob::class)]
class ProcessMovieImagesJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cenário Único e Completo: Faz o download fake das imagens do filme e da coleção,
     * valida a inserção nos discos S3 corretos e verifica a atualização das URLs no banco.
     */
    public function test_deve_baixar_imagens_do_filme_e_da_colecao_e_salvar_nos_discos_s3_corretos(): void
    {
        // 1. Inicializa os fakes do Storage interceptando os dois buckets do S3
        Storage::fake('s3');
        Storage::fake('s3_collections');

        // 2. Cria a coleção primeiro
        $colecao = Colecao::factory()->create([
            'poster_path'   => null,
            'backdrop_path' => null,
        ]);

        // Cria o filme já associando-o à coleção criada (ajuste 'colecao_id' para o nome real da sua FK se for diferente)
        $movie = Movie::factory()->create([
            'colecao_id'     => $colecao->id,
            'poster_path_br' => null,
            'backdrop_path'  => null,
        ]);

        // Garante que o Laravel carregue o relacionamento na memória para o Job usar
        $movie->setRelation('colecao', $colecao);

        // 3. Mocka as respostas HTTP do TMDB simulando o download dos binários de imagem
        Http::fake([
            'image.tmdb.org/t/p/original/poster_br.jpg' => Http::response('fake-binary-poster-data', 200, [
                'Content-Type' => 'image/jpeg'
            ]),
            'image.tmdb.org/t/p/original/backdrop.jpg'  => Http::response('fake-binary-backdrop-data', 200, [
                'Content-Type' => 'image/jpeg'
            ]),
            'image.tmdb.org/t/p/original/colecao_poster.jpg' => Http::response('fake-binary-colecao-data', 200, [
                'Content-Type' => 'image/jpeg'
            ]),
        ]);

        // Payload estruturado exatamente como o StoreMovieJob envia
        $payloadImagens = [
            'poster_path_br'  => 'https://image.tmdb.org/t/p/original/poster_br.jpg',
            'backdrop_path'   => 'poster_br.jpg', // Testa também passando sem a URL completa (remota)
            'poster_thumb_br' => null,
            'poster_path_us'  => null,
            'poster_thumb_us' => null,
            'colecao' => [
                'poster_path'   => 'colecao_poster.jpg',
                'poster_thumb'  => null,
                'backdrop_path' => null
            ]
        ];

        // 4. Instancia e roda o Job
        $job = new ProcessMovieImagesJob($movie, $payloadImagens);
        $job->handle();

        // 5. ASSERÇÕES NO BANCO DE DADOS (Filme principal)
        $movie->refresh();

        // Garante que o banco não salvou a URL original do TMDB, e sim o novo path gerado pelo S3
        $this->assertNotNull($movie->poster_path_br);
        $this->assertStringStartsWith('/posters/', $movie->poster_path_br);

        $this->assertNotNull($movie->backdrop_path);
        $this->assertStringStartsWith('/posters/', $movie->backdrop_path);

        // 6. ASSERÇÕES NO BANCO DE DADOS (Coleção)
        $colecao->refresh();
        $this->assertNotNull($colecao->poster_path);
        $this->assertStringStartsWith('/collections/', $colecao->poster_path);

        // 7. ASSERÇÕES DO STORAGE (Verifica se os arquivos físicos realmente foram gravados nos locais certos)

        // Remove a barra inicial '/' para validar o nome do arquivo dentro do Storage local
        $pathPosterNoDisco = ltrim($movie->poster_path_br, '/');
        $pathBackdropNoDisco = ltrim($movie->backdrop_path, '/');
        $pathColecaoNoDisco = ltrim($colecao->poster_path, '/');

        // Garante que as imagens principais foram parar no disco 's3'
        Storage::disk('s3')->assertExists($pathPosterNoDisco);
        Storage::disk('s3')->assertExists($pathBackdropNoDisco);

        // Garante que a imagem da coleção foi isolada no disco 's3_collections'
        Storage::disk('s3_collections')->assertExists($pathColecaoNoDisco);
    }
}
