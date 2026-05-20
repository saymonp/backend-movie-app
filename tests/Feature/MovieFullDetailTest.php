<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Movie;
use App\Models\Genero;
use App\Models\Diretor;
use App\Http\Controllers\MovieController;
use PHPUnit\Framework\Attributes\CoversClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Colecao;
use App\Models\User;
use App\Models\Review;
use App\Services\TmdbService;
use App\Jobs\StoreMovieJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;

#[CoversClass(MovieController::class)]
class MovieFullDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Teste 1: Garante que o filme é encontrado extraindo o ID do slug corretamente,
     * mesmo que o restante do texto mude.
     */
    public function test_pode_carregar_detalhes_do_filme_passando_slug_com_id(): void
    {
        $movie = Movie::factory()->create(['titulo_br' => 'Homem Aranha']);

        // Simula a URL padrão: id-slug-do-filme
        $slug = "{$movie->id}-homem-aranha";

        $response = $this->getJson("/api/movies/{$slug}/full-details");

        $response->assertStatus(200)
            ->assertJsonPath('movie.id', $movie->id)
            ->assertJsonStructure(['movie', 'collection', 'related', 'lists', 'reviews']);
    }

    /**
     * Teste 2: Garante o comportamento quando os filmes da coleção já existem 
     * e estão processados no banco local (não dispara API nem Job).
     */
    public function test_retorna_colecao_do_banco_local_se_estiver_com_status_processado(): void
    {
        Queue::fake();

        $colecao = Colecao::factory()->create();

        // Filme principal da rota
        $movie = Movie::factory()->create(['colecao_id' => $colecao->id]);

        // Outro filme da mesma coleção já processado no banco
        $sequencia = Movie::factory()->create([
            'colecao_id' => $colecao->id,
            'status' => 'processado',
            'slug_en' => 'spider-man-2'
        ]);

        $slug = "{$movie->id}-spider-man";
        $response = $this->getJson("/api/movies/{$slug}/full-details");

        $response->assertStatus(200);
        
        // Garante que a sequência veio no payload da coleção
        $this->assertEquals($sequencia->id, $response->json('collection.0.id'));

        // Garante que NENHUM job foi enviado para a fila
        Queue::assertNothingPushed();
    }

    /**
     * Teste 3: Cenário crítico. Se a coleção estiver incompleta no banco,
     * ele deve mocar a API do TMDB, criar o registro temporário e disparar o Job para a fila 'high'.
     */
    public function test_busca_dados_no_tmdb_e_dispara_job_se_colecao_estiver_incompleta(): void
    {
        Queue::fake();

        $colecao = Colecao::factory()->create(['tmdb_id' => 999]);
        $movie = Movie::factory()->create([
            'colecao_id' => $colecao->id,
            'tmdb_id' => 557 // ID do filme atual
        ]);

        // Mockando o TmdbService
        $this->mock(TmdbService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCollectionDetails')
                ->once()
                ->with(999)
                ->andReturn([
                    [
                        'id' => 558, // ID de um filme novo na coleção
                        'original_title' => 'Spider-Man 2',
                        'title' => 'Homem-Aranha 2',
                        'poster_path' => '/path.jpg',
                        'vote_average' => 7.5
                    ],
                    [
                        'id' => 557, // O próprio filme atual (o controller deve ignorar)
                        'title' => 'Spider-Man'
                    ]
                ]);
        });

        $slug = "{$movie->id}-filme-incompleto";
   
        $response = $this->getJson("/api/movies/{$slug}/full-details");

        $response->assertStatus(200);
        
        // Verifica se o filme novo foi adicionado à resposta como 'processando'
        $response->assertJsonPath('collection.0.titulo_br', 'Homem-Aranha 2');
        $response->assertJsonPath('collection.0.status', 'processando');

        // Garante que o filme novo foi salvo no banco de dados temporariamente
        $this->assertDatabaseHas('movies', [
            'tmdb_id' => 558,
            'status' => 'processando'
        ]);

        // Garante que o StoreMovieJob foi despachado na fila correta ('high')
        Queue::assertPushed(StoreMovieJob::class, function ($job) {
            return $job->queue === 'high';
        });
    }

    /**
     * Teste 4: Testa o relacionamento de reviews e se a propriedade condicional 'is_liked'
     * funciona quando um usuário está autenticado.
     */
    public function test_retorna_se_usuario_autenticado_deu_like_na_review(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        // Cria uma review para este filme
        $review = Review::factory()->create(['movie_id' => $movie->id]);

        // Simula o vínculo de que o usuário curtiu essa review (tabela pivô de likes)
        $review->likes()->attach($user->id);

        $slug = "{$movie->id}-filme-reviews";

        // Fazemos a requisição simulando estar logado (actingAs) usando guard 'sanctum'
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/movies/{$slug}/full-details");

        $response->assertStatus(200)
            ->assertJsonPath('reviews.0.is_liked', true);
    }
}
