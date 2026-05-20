<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Jobs\StoreMovieJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Http\Controllers\MovieController;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;


#[CoversClass(MovieController::class)]
class MovieImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cenário 1: Bloqueia usuários que não possuem a permissão correta.
     */
    public function test_usuario_sem_permissao_nao_pode_importar_filme(): void
    {
        Queue::fake();

        // Cria um usuário comum (sem permissões)
        $user = User::factory()->create();

        $tmdbId = 550; // Clube da Luta, por exemplo

        // Faz a requisição autenticado como o usuário comum
        $response = $this->actingAs($user)
            ->postJson("/api/admin/movies/single/{$tmdbId}");

        // Asserções
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'User does not have the right roles.');

        // Garante que NENHUM job foi parar na fila
        Queue::assertNothingPushed();
    }

    /**
     * Cenário 2: Permite a importação se o filme for novo.
     */
    public function test_usuario_autorizado_pode_importar_filme_inedito(): void
    {
        Queue::fake();

        $admin = $this->createAdminUser();

        $tmdbId = 558;

        // Garante que o filme REALMENTE não existe no banco antes do teste
        $this->assertDatabaseMissing('movies', ['tmdb_id' => $tmdbId]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/movies/single/{$tmdbId}");

        // Asserções da resposta (Status 202 Accepted)
        $response->assertStatus(202);
        $response->assertJson([
            'message' => 'O filme foi enviado para processamento prioritário.',
            'data' => [
                'tmdb_id' => (string)$tmdbId
            ]
        ]);

        // Garante que o Job correto foi enviado para a fila 'high'
        Queue::assertPushed(StoreMovieJob::class, function ($job) use ($tmdbId) {
            return $job->queue === 'high';
        });
    }

    /**
     * Cenário 3: Atualiza de forma prioritária caso o filme já exista.
     */
    public function test_usuario_autorizado_ao_importar_filme_existente_recebe_mensagem_de_atualizacao(): void
    {
        Queue::fake();

        $admin = $this->createAdminUser();

        $tmdbId = 557;

        // Força o filme a já existir no banco de dados local
        Movie::factory()->create(['tmdb_id' => $tmdbId]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/movies/single/{$tmdbId}");

        // Asserções
        $response->assertStatus(202);
        $response->assertJson([
            'message' => 'Este filme já existe no catálogo e será atualizado em processamento prioritário',
            'data' => [
                'tmdb_id' => (string)$tmdbId
            ]
        ]);

        // Garante que mesmo existindo, o Job foi empurrado para a fila prioritária refazer o sync
        Queue::assertPushed(StoreMovieJob::class, function ($job) {
            return $job->queue === 'high';
        });
    }

    /**
     * Cria um usuário de teste já com a Role de Admin e suas permissões.
     */
    private function createAdminUser(): User
    {
        // 1. Definir e criar as permissões no banco (usando o guard correto)
        $permissions = [
            'import movies',
            'delete movies',
            'delete reviews',
            'assign roles'
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'api'
            ]);
        }

        // 2. Criar ou buscar a Role de Admin
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api'
        ]);

        // 3. Sincronizar todas as permissões criadas para esta Role
        // O método syncPermissions do Spatie atrela o array de permissões à Role de uma vez só
        $adminRole->syncPermissions($permissions);

        // 4. Criar o Usuário utilizando a Factory
        $adminUser = User::factory()->create();

        // 5. Atribuir a Role ao Usuário
        // Como você está usando o guard 'api', o Spatie exige que o usuário use esse guard
        $adminUser->assignRole($adminRole);

        return $adminUser;
    }
}
