<?php

namespace Database\Factories;

use App\Models\Lista;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lista>
 */
class ListaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titulo = $this->faker->sentence(3);
        return [
            'titulo' => $titulo, 
            'comentario' => $this->faker->paragraph(), 
            'user_id' => User::factory(), 
            'slug' => Str::slug($titulo), 
            'idioma' => $this->faker->randomElement(['en', 'pt']),
            'publica' => true,
            'is_default' => false
        ];
    }
}
