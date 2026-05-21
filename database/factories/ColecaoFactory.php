<?php

namespace Database\Factories;

use App\Models\Colecao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Colecao>
 */
class ColecaoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tituloBr = $this->faker->sentence(3);
        return [
            'tmdb_id' => $this->faker->unique()->numberBetween(100, 999999),
            'nome' => $tituloBr,
            'poster_path' => 'https://image.tmdb.org/t/p/original/' . Str::random(26) . '.jpg',
            'poster_thumb' => 'https://image.tmdb.org/t/p/w500/' . Str::random(26) . '.jpg',
            'backdrop_path' => 'https://image.tmdb.org/t/p/original/' . Str::random(26) . '.jpg',
        ];
    }
}
