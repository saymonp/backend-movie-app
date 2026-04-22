<?php

namespace Tests\Feature;

use Tests\TestCase;

class MovieTest extends TestCase
{
    public function test_can_process_movie_data_from_fixture()
    {
        $this->withoutExceptionHandling();
        // 1. Carrega o conteúdo do JSON
        $json = file_get_contents(base_path('tests/Fixtures/movies.json'));
        $data = json_decode($json, true);

        // 2. Simula uma ação com esses dados
        $response = $this->postJson('/api/movies', $data[1]);

        $response->dump();
        // 3. Verifica o resultado
        $response->assertStatus(201)
                 ->assertJsonPath('tmdb_id', $data[1]['tmdb_id']);
                 
    }

    public function test_can_show_movie()
    {
        // 1. Carrega o conteúdo do JSON
        $json = file_get_contents(base_path('tests/Fixtures/movies.json'));
        $data = json_decode($json, true);

        // 2. Simula uma ação com esses dados
        $response = $this->postJson('/api/movies', $data[1]);

        $response->dump();
        // 3. Verifica o resultado
        $response->assertStatus(201)
                 ->assertJsonPath('tmdb_id', $data[1]['tmdb_id']);
                 
        $this->withoutExceptionHandling();
        // 1. Carrega o conteúdo do JSON
        $json = file_get_contents(base_path('tests/Fixtures/movies.json'));
        $data = json_decode($json, true);

        // 2. Simula uma ação com esses dados
        $response = $this->postJson('/api/movies/show/', $data[1]);

        $response->dump();
        // 3. Verifica o resultado
        $response->assertStatus(201)
                 ->assertJsonPath('tmdb_id', $data[1]['tmdb_id']);
                 
    }
}
