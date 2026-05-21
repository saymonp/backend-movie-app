<?php

namespace Database\Factories;

use App\Models\Diretor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diretor>
 */
class DiretorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => $this->faker->name(),
        ];
    }
}
