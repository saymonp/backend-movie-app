<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Http\Controllers\ReviewController;
use Spatie\Permission\Models\Role;

#[CoversClass(ReviewController::class)]
class ReviewTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
    /**
     * Cenário 1: Listar todas as reviews (Rota Geral /api/reviews) sem passar filme.
     */
    public function test_pode_listar_todas_as_reviews_com_relacionamentos_paginados(): void
    {
        $filme = Movie::factory()->create(['titulo_br' => 'Blade Runner']);

        // Cria 2 reviews para o filme
        Review::factory()->count(2)->create(['movie_id' => $filme->id]);

        $response = $this->getJson('/api/reviews');

        $response->assertStatus(200);

        // Verifica se envelopou no 'data' por causa da paginação
        $response->assertJsonCount(2, 'data');

        // Valida se os relacionamentos desejados foram carregados (User e Movie)
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'user' => ['id', 'name', 'avatar'],
                    'movie' => ['id', 'titulo_br'],
                    'likes_count',
                    'is_liked'
                ]
            ]
        ]);
    }

    /**
     * Cenário 2: Listar reviews filtrando por um filme específico (/api/movies/{movie_id}/reviews).
     */
    public function test_pode_filtrar_reviews_por_um_filme_especifico(): void
    {
        $filmeAlvo = Movie::factory()->create();
        $outroFilme = Movie::factory()->create();

        // Cria review para o filme que queremos e para o que não queremos
        $reviewDoFilme = Review::factory()->create(['movie_id' => $filmeAlvo->id, 'comentario' => 'Filme sensacional!']);
        Review::factory()->create(['movie_id' => $outroFilme->id, 'comentario' => 'Achei bem ruim.']);

        // Executa a requisição na rota do filme específico
        $response = $this->getJson("/api/{$filmeAlvo->id}/reviews");

        $response->assertStatus(200);

        // Deve trazer apenas a review vinculada ao $filmeAlvo
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.comentario', 'Filme sensacional!');
    }

    /**
     * Cenário 3: Filtro 'user_only' trazendo apenas as reviews do próprio usuário logado.
     */
    public function test_usuario_logado_pode_filtrar_apenas_suas_proprias_reviews(): void
    {
        $userLogado = User::factory()->create();
        $outroUser = User::factory()->create();

        Review::factory()->create(['user_id' => $userLogado->id, 'comentario' => 'Minha própria review']);
        Review::factory()->create(['user_id' => $outroUser->id, 'comentario' => 'Review de outra pessoa']);

        $response = $this->actingAs($userLogado, 'sanctum')
            ->getJson('/api/reviews?user_only=true');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.comentario', 'Minha própria review');
    }

    /**
     * Cenário 4: Checar se o is_liked retorna true quando o usuário logado curtiu a review.
     */
    public function test_retorna_is_liked_como_true_se_o_usuario_autenticado_curtiu_a_review(): void
    {
        $userLogado = User::factory()->create();
        $review = Review::factory()->create();

        // O usuário logado curte a review
        $review->likes()->attach($userLogado->id);

        $response = $this->actingAs($userLogado, 'sanctum')
            ->get('/api/reviews');

        $response->assertStatus(200);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id
        ]);
        // Como o usuário curtiu, o withExists deve marcar como true
        $response->assertJsonPath('data.0.is_liked', true);
        $response->assertJsonPath('data.0.likes_count', 1);
    }

    public function test_retorna_is_liked_como_true_se_o_usuario_autenticado_curtiu_a_review_toggle_like(): void
    {
        $userDonoReview = User::factory()->create();
        dump('dono review', $userDonoReview->id);
        $userLogado = User::factory()->create();
        $userLogado1 = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $userDonoReview->id]);

        // O usuário logado curte a review através do método toggleLike
        $responseToggleLike = $this->actingAs($userLogado, 'sanctum')->post("api/reviews/{$review->id}/like");
        $responseToggleLike->assertStatus(200);
        $responseToggleLike->assertJsonPath('is_liked', true);
        $responseToggleLike->assertJsonPath('message', "Like adicionado");

        $responseToggleLike1 = $this->actingAs($userLogado1, 'sanctum')->post("api/reviews/{$review->id}/like");
        $responseToggleLike1->assertStatus(200);
        $responseToggleLike1->assertJsonPath('is_liked', true);
        $responseToggleLike1->assertJsonPath('message', "Like adicionado");
        $responseToggleLike1->assertJsonPath('likes_count', 2);

        $response = $this->actingAs($userLogado, 'sanctum')
            ->get('/api/reviews');

        $response->assertStatus(200);

        $response->dump();

        // Como o usuário curtiu, o withExists deve marcar como true
        $response->assertJsonPath('data.0.is_liked', true); // erro retorna false
        $response->assertJsonPath('data.0.likes_count', 2); // erro retorna 0
    }
    /**
     * TESTES DO MÉTODO: store
     */

    public function test_usuario_autenticado_pode_criar_uma_nova_review_com_sucesso(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $payload = [
            'titulo' => 'Filme Excelente!',
            'comentario' => 'História muito bem construída e ótimos efeitos visuais.',
            'rating' => 4.5,
            'tags' => ['Favorito', 'Sci-Fi']
        ];

        // Rota baseada no seu padrão: /api/movies/{movie_id}/reviews
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/movies/{$movie->id}/reviews", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('titulo', 'Filme Excelente!');
        $response->assertJsonPath('rating', 4.5);

        // Verifica se persistiu na tabela correspondente (ex: 'reviews')
        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'titulo' => 'Filme Excelente!',
            'rating' => 4.5
        ]);
    }

    public function test_usuario_autenticado_atualiza_review_existente_ao_enviar_mesmo_movie_id_updateOrCreate(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        // Já existe uma review prévia criada por ele para este filme
        $reviewExistente = Review::factory()->create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'titulo' => 'Opinião Antiga',
            'rating' => 2.0
        ]);

        $payload = [
            'titulo' => 'Mudei de ideia, é muito bom',
            'comentario' => 'Revisto em 2026 e gostei muito mais.',
            'rating' => 5.0
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/movies/{$movie->id}/reviews", $payload);

        $response->assertStatus(200);

        // Garante que o registro foi atualizado e não duplicado
        $this->assertDatabaseHas('reviews', [
            'id' => $reviewExistente->id,
            'titulo' => 'Mudei de ideia, é muito bom',
            'rating' => 5.0
        ]);

        $this->assertEquals(1, Review::count());
    }

    public function test_falha_na_validacao_se_o_rating_estiver_fora_do_limite(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        // Nota inválida (maior que 5)
        $payload = [
            'rating' => 6.0
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/movies/{$movie->id}/reviews", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rating']);
    }

    /**
     * TESTES DO MÉTODO: destroy
     */

    public function test_dono_da_review_pode_deleta_la(): void
    {
        $dono = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $dono->id]);

        $response = $this->actingAs($dono, 'sanctum')
            ->deleteJson("/api/reviews/{$review->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Review removida');

        // Confirma a exclusão física do registro no banco
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_usuario_administrador_pode_deletar_review_de_terceiros(): void
    {
        // Configura a role admin
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $outroUsuario = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $outroUsuario->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/reviews/{$review->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_usuario_comum_nao_pode_deletar_review_de_outra_pessoa(): void
    {
        $dono = User::factory()->create();
        $invasor = User::factory()->create();

        $review = Review::factory()->create(['user_id' => $dono->id]);

        $response = $this->actingAs($invasor, 'sanctum')
            ->deleteJson("/api/reviews/{$review->id}");

        // Resposta de erro esperada: 403 Forbidden
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Não autorizado');

        // O registro precisa continuar intacto no banco
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }
}
