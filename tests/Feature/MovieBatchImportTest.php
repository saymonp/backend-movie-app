<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Http\Controllers\MovieController;
use Illuminate\Support\Facades\Queue; // Trocamos Artisan por Queue
use Illuminate\Foundation\Console\QueuedCommand; // Importante para a asserção

#[CoversClass(MovieController::class)]
class MovieBatchImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Limpa o cache do Spatie para evitar conflitos entre testes
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Cenário 1: Usuário comum (sem a permissão) é bloqueado.
     */
    public function test_usuario_sem_permissao_nao_pode_disparar_importacao_em_lote(): void
    {
        Queue::fake();

        // Cria usuário comum (sem roles/permissions)
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/movies/batch/20');

        // Asserções
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'User does not have the right roles.');

        Queue::assertNothingPushed();
    }

    /**
     * Cenário 2: Admin com a permissão dispara o lote com sucesso.
     */
    public function test_usuario_autorizado_pode_disparar_importacao_em_lote_com_limite(): void
    {
        Queue::fake();

        // Cria o admin usando o método auxiliar do Spatie
        $admin = $this->createAdminUser();

        $limit = 20;

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/movies/batch/{$limit}");

        // Asserções da Resposta HTTP (202 Accepted)
        $response->assertStatus(202);
        $response->assertJson([
            'message' => "O processo de importação de {$limit} filmes foi iniciado em segundo plano."
        ]);

        Queue::assertPushed(QueuedCommand::class);
    }

    /**
     * Método Auxiliar: Cria o cenário do Spatie idêntico ao seu banco
     */
    private function createAdminUser(): User
    {
        // Cria a permissão necessária
        Permission::firstOrCreate([
            'name' => 'import movies',
            'guard_name' => 'api'
        ]);

        // Cria a role admin
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api'
        ]);

        // Sincroniza a permissão à role
        $adminRole->syncPermissions(['import movies']);

        // Cria o usuário, atribui a role e retorna
        $adminUser = User::factory()->create();
        $adminUser->assignRole($adminRole);

        return $adminUser;
    }
}
