<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\User;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'titulo' => $this->faker->sentence(3),
            'comentario' => $this->faker->paragraph(),
            'rating' => $this->faker->numberBetween(1, 5),

            // Em vez de números aleatórios, criamos registros válidos automaticamente
            'user_id' => User::factory(),
            'movie_id' => Movie::factory(),
        ];
    }

    /**
     * ESTADO CUSTOMIZADO: Adiciona likes na tabela pivô após criar a review.
     *
     * @param int $quantidade Quantidade de likes para gerar
     */
    public function comLikes(int $quantidade = 3): static
    {
        return $this->afterCreating(function (Review $review) use ($quantidade) {
            // Cria os usuários que darão o "like"
            $usuarios = User::factory()->count($quantidade)->create();

            // Vincula os usuários na tabela pivô usando o relacionamento da Model
            $review->likes()->attach($usuarios->pluck('id'));
        });
    }
}
