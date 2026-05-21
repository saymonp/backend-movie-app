<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MovieFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Geramos um título base para usar na criação dos slugs coerentes
        $tituloBr = $this->faker->sentence(3);
        $tituloEn = $this->faker->sentence(3);

        return [
            // IDs de integrações externas
            'tmdb_id' => $this->faker->unique()->numberBetween(100, 999999),
            'imdb_id' => 'tt' . $this->faker->unique()->numerify('#######'), // Ex: tt0145487
            'colecao_id' => null, //$this->faker->optional()->numberBetween(1, 50), // Pode ser um ID ou null

            // Títulos e Textos descritivos
            'titulo_original' => $tituloEn,
            'titulo_br' => $tituloBr,
            'titulo_en' => $tituloEn,
            'descricao_br' => $this->faker->paragraph(),
            'descricao_en' => $this->faker->paragraph(),
            'tagline_br' => $this->faker->optional()->sentence(),
            'tagline_en' => $this->faker->optional()->sentence(),

            // Informações técnicas do filme
            'rating' => $this->faker->randomFloat(1, 1, 10), // Nota de 1.0 a 10.0
            'duracao' => $this->faker->numberBetween(80, 210), // Duração em minutos
            'lingua_origem' => $this->faker->randomElement(['en', 'pt', 'es', 'fr', 'ja']),
            'revenue' => $this->faker->numberBetween(50000, 2000000000), // Bilheteria até 2 bilhões
            'popularity' => $this->faker->randomFloat(4, 5, 500), // Ex: 24.8208
            'release_date' => $this->faker->date(), // Formato Y-m-d

            // Imagens estruturadas no padrão do TMDB
            'poster_path_br' => 'https://image.tmdb.org/t/p/original/' . Str::random(26) . '.jpg',
            'poster_thumb_br' => 'https://image.tmdb.org/t/p/w500/' . Str::random(26) . '.jpg',
            'backdrop_path' => 'https://image.tmdb.org/t/p/original/' . Str::random(26) . '.jpg',
            'poster_path_us' => 'https://image.tmdb.org/t/p/original/' . Str::random(26) . '.jpg',
            'poster_thumb_us' => 'https://image.tmdb.org/t/p/w500/' . Str::random(26) . '.jpg',

            // Slugs gerados a partir dos títulos falsos
            'slug_pt' => Str::slug($tituloBr),
            'slug_en' => Str::slug($tituloEn),

            // Controle interno e links
            'homepage' => $this->faker->optional()->url(),
            'status' => 'processado', // Estático , ou usar randomElement
        ];
    }
}