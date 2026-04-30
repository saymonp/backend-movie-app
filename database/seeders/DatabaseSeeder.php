<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\RoleSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
        ]);

        // 2º: Agora criamos o usuário e atribuímos a role
        //$admin = User::factory()->create([
        //    'name' => 'Admin User',
        //    'email' => 'admin@example.com', // Seu e-mail de acesso
        //    'password' => bcrypt('password'), // login tradicional
        //]);

        // Só executa se for ambiente de desenvolvimento (local)
        //if (app()->environment('local')) {
        //    User::factory(10)->create();
        //    $this->call(MovieDummySeeder::class);
       // }

        // Atribui a role que foi criada no RoleSeeder
        //$admin->assignRole('admin');
    }
}
