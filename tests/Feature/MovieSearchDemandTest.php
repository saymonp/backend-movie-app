<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Jobs\StoreMovieJob;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Http\Controllers\MovieController;
/**
 * Necessário alterar a query para:
*->whereRaw('LOWER(titulo_br) LIKE ?', ["%{$search}%"])
*/
#[CoversClass(MovieController::class)]
class MovieSearchDemandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cenário 1: Filme não existe no banco local, mas o TMDB encontra.
     * Deve salvar um registro temporário, disparar o Job 'high' e retornar 202.
     */
    public function test_busca_por_demanda_localiza_no_tmdb_e_dispara_importacao(): void
    {
        Queue::fake();

        $searchQuery = 'Matrix Resurrections';
        $lang = 'pt-BR';
        $mockTmdbId = 590706;

        // 1. Mockar o TmdbService usando o Container app() que você implementou
        $this->mock(TmdbService::class, function (MockInterface $mock) use ($searchQuery, $lang, $mockTmdbId) {
            $mock->shouldReceive('searchMovie')
                ->once()
                ->with($searchQuery, $lang)
                ->andReturn([
                    [
                        'id' => $mockTmdbId,
                        'original_title' => 'The Matrix Resurrections',
                        'title' => 'Matrix Resurrections',
                        'poster_path' => '/matrix.jpg',
                    ]
                ]);
        });

        // Garante que o banco local está completamente vazio para forçar o fluxo do TMDB
        $this->assertDatabaseMissing('movies', ['tmdb_id' => $mockTmdbId]);

        // 2. Executar a requisição GET no método indexAddToList
        $response = $this->getJson("/api/movies/listas?search={$searchQuery}&lang={$lang}");
     
        // 3. Asserções do Response HTTP (Status 202 Accepted)
        $response->assertStatus(202);
        $response->assertJsonPath('message', 'Filme não encontrado localmente, mas localizado no TMDB. Estamos importando agora!');
        $response->assertJsonPath('tmdb_id', $mockTmdbId);
        $response->assertJsonPath('status', 'processando');

        // 4. Garante que o registro temporário foi persistido via firstOrCreate no banco
        $this->assertDatabaseHas('movies', [
            'tmdb_id' => $mockTmdbId,
            'status' => 'processando',
            'titulo_original' => 'The Matrix Resurrections'
        ]);

        // 5. Garante que o Job de alta prioridade foi parar na fila certa
        Queue::assertPushed(StoreMovieJob::class, function ($job) use ($mockTmdbId) {
            return $job->queue === 'high';
        });
    }

    /**
     * Cenário 2: Filme não existe localmente e o TMDB também não encontra nada.
     * Deve retornar Status 404 Not Found.
     */
    public function test_busca_por_demanda_retorna_404_quando_nao_encontra_no_tmdb(): void
    {
        Queue::fake();

        $searchQuery = 'FilmeInexistenteQueNinguemNuncaOuviuFalar';

        // O TMDB retorna um array vazio quando não acha nada
        $this->mock(TmdbService::class, function (MockInterface $mock) use ($searchQuery) {
            $mock->shouldReceive('searchMovie')
                ->once()
                ->with($searchQuery, 'pt-BR')
                ->andReturn([]);
        });

        $response = $this->getJson("/api/movies/listas?search={$searchQuery}");

        // Asserções
        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Nenhum filme encontrado.');

        // Garante que nenhum Job foi gerado
        Queue::assertNothingPushed();
    }

    /**
     * Cenário 3: O filme já existe no banco de dados local.
     * Deve retornar a lista com o filme direto do banco (Status 200 OK)
     * e NENHUMA chamada ao TMDB ou Fila deve ser feita.
     */
    public function test_busca_retorna_filme_direto_do_banco_se_ele_ja_existir(): void
    {
        Queue::fake();

        $searchQuery = 'Matrix';

        // 1. Criar o filme e o relacionamento com o diretor no banco local para a busca ILIKE encontrar
        $movie = Movie::factory()->create([
            'titulo_br' => 'The Matrix',
            'titulo_original' => 'The Matrix',
            'release_date' => '1999-03-31',
            'status' => 'processado'
        ]);

        // 2. Mockar o TmdbService para GARANTIR que ele NÃO será chamado
        // Se o controlador tentar chamar o TMDB, o Mockery vai quebrar o teste dizendo "shouldNotReceive"
        $this->mock(TmdbService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('searchMovie');
        });

        // 3. Executar a requisição GET
        $response = $this->getJson("/api/movies/listas?search={$searchQuery}");

        // 4. Asserções do Response HTTP (Status 200 OK)
        $response->assertStatus(200);
        
        // Verifica se a resposta é um array contendo o filme criado
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.id', $movie->id);
        $response->assertJsonPath('0.titulo_br', 'The Matrix');

        // 5. Segurança absoluta: garante que a fila de importação ficou intocada
        Queue::assertNothingPushed();
    }
}