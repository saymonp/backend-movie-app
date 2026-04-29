<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Limpa o cache para evitar conflitos de memória
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Criar as Permissões primeiro (Sempre com o guard_name api)
        $permissions = [
            'import movies',
            'delete movies',
            'delete reviews',
            'assign roles'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api' // Força o guard da API
            ]);
        }

        // 3. Criar as Roles
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api'
        ]);

        $userRole = Role::firstOrCreate([
            'name' => 'user',
            'guard_name' => 'api'
        ]);

        // 4. Atribuir permissões à Role Admin
        // Passamos o array de nomes, e o Spatie faz a mágica
        $adminRole->syncPermissions($permissions);

        // 5. Criar o Usuário Admin
        // Usamos updateOrCreate para não dar erro de e-mail duplicado ao rodar o seeder de novo
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'slug' => 'Adm'
            ]
        );

        // Atribui a role
        $admin->assignRole($adminRole);
    }
}
