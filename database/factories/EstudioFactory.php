<?php

namespace Database\Factories;

use App\Models\Estudio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estudio>
 */
class EstudioFactory extends Factory
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
