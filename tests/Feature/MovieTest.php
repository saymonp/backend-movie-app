<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Movie;
use App\Models\Genero;
use App\Models\Diretor;
use App\Http\Controllers\MovieController;
use PHPUnit\Framework\Attributes\CoversClass;
use Illuminate\Foundation\Testing\RefreshDatabase;

#[CoversClass(MovieController::class)]
class MovieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Teste 1: Garante que a listagem padrão traz os filmes paginados e ordenados por data de lançamento.
     */
    public function test_pode_listar_todos_os_filmes_com_ordenacao_padrao(): void
    {
        // Cria 2 filmes com datas de lançamento diferentes
        $filmeAntigo = Movie::factory()->create(['release_date' => '2023-01-01']);
        $filmeNovo = Movie::factory()->create(['release_date' => '2026-01-01']);

        $response = $this->getJson('/api/movies');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'per_page'])
            ->assertJsonCount(2, 'data');

        // Garante que o mais novo veio primeiro (ordenabilidade padrão)
        $this->assertEquals($filmeNovo->id, $response->json('data.0.id'));
    }

    /**
     * Teste 2: Garante o funcionamento do filtro por ano de lançamento.
     */
    public function test_pode_filtrar_filmes_por_ano_especifico(): void
    {
        $filme2024 = Movie::factory()->create(['release_date' => '2024-05-15']);
        $filme2026 = Movie::factory()->create(['release_date' => '2026-02-20']);

        $response = $this->getJson('/api/movies?ano=2026');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $filme2026->id);
    }

    /**
     * Teste 3: Testa o filtro relacional (Muitos para Muitos) com Gêneros.
     */
    public function test_pode_filtrar_filmes_por_genero_via_array_de_ids(): void
    {
        // Cria dois gêneros separados
        $acao = Genero::factory()->create();
        $drama = Genero::factory()->create();

        // Cria os filmes e vincula os gêneros a eles
        $filmeAcao = Movie::factory()->create();
        $filmeAcao->generos()->attach($acao->id);

        $filmeDrama = Movie::factory()->create();
        $filmeDrama->generos()->attach($drama->id);

        // Passa o array de gêneros na URL: ?generos[]=1
        $response = $this->getJson("/api/movies?generos[]={$acao->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $filmeAcao->id);
    }

    /**
     * Teste 4: Testa o comportamento da flag booleana de maiores bilheterias.
     */
    public function test_pode_ordenar_por_maior_bilheteria(): void
    {
        $filmeBaixaBilheteria = Movie::factory()->create(['revenue' => 100000]); // 100k
        $filmeAltaBilheteria = Movie::factory()->create(['revenue' => 950000000]); // 950M

        // Ativa o booleano ?bilheteria=true
        $response = $this->getJson('/api/movies?bilheteria=true');

        $response->assertStatus(200);
        
        // O de maior bilheteria deve ser o primeiro item do array
        $this->assertEquals($filmeAltaBilheteria->id, $response->json('data.0.id'));
    }
}