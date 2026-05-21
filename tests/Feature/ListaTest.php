<?php

namespace Tests\Feature;

use App\Models\Lista;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Http\Controllers\ListaController;
use Spatie\Permission\Models\Role;

#[CoversClass(ListaController::class)]
class ListaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Garante que o cache de permissões/roles do Spatie esteja limpo, caso interfira no Sanctum
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * Cenário 1: Um visitante deslogado visualiza os detalhes da lista.
     * Deve trazer os filmes anexados, contagem de likes corretas, e is_liked como false.
     */
    public function test_visitante_deslogado_pode_visualizar_detalhes_da_lista(): void
    {
        // 1. Criar a estrutura no banco usando as Factories
        $lista = Lista::factory()->create([
            'titulo' => 'Melhores de Ficção Científica',
            'publica' => true
        ]);

        $movie = Movie::factory()->create([
            'titulo_br' => 'Interestelar',
            'rating' => 8.6
        ]);

        // Vincula o filme à lista (ajuste o nome do relacionamento se for diferente na sua model)
        $lista->movies()->attach($movie->id);

        // Simula 2 usuários dando like nesta lista (ajuste o relacionamento 'likes' se necessário)
        $usuariosQueCurtiram = User::factory()->count(2)->create();
        $lista->likes()->attach($usuariosQueCurtiram->pluck('id'));

        // 2. Executa a requisição sem estar autenticado
        $response = $this->getJson("/api/listas/{$lista->id}");

        // 3. Asserções do Response HTTP (200 OK)
        $response->assertStatus(200);
        $response->assertJson([
            'id' => $lista->id,
            'titulo' => 'Melhores de Ficção Científica',
            'likes_count' => 2,
            'is_liked' => false, // Visitante não está logado, então não deu like
        ]);

        // Valida se apenas as colunas selecionadas do filme foram retornadas
        $response->assertJsonPath('movies.0.id', $movie->id);
        $response->assertJsonPath('movies.0.titulo_br', 'Interestelar');
        $response->assertJsonPath('movies.0.rating', 8.6);

        // Garante que não trouxe colunas que foram filtradas para fora no select (ex: descricao_br)
        $this->assertArrayNotHasKey('descricao_br', $response->json('movies.0'));
    }

    /**
     * Cenário 2: Usuário logado que curtiu a lista visualiza os detalhes.
     * O campo is_liked deve retornar true.
     */
    public function test_usuario_logado_que_curtiu_a_lista_recebe_is_liked_como_true(): void
    {
        // 1. Criar o usuário que vai acessar a rota e a lista
        $userLogado = User::factory()->create();
        $lista = Lista::factory()->create();

        // O usuário logado dá like na lista
        $lista->likes()->attach($userLogado->id);

        // 2. Executa a requisição autenticado via guard sanctum
        $response = $this->actingAs($userLogado, 'sanctum')
            ->getJson("/api/listas/{$lista->id}");

        // 3. Asserções
        $response->assertStatus(200);
        $response->assertJson([
            'id' => $lista->id,
            'likes_count' => 1,
            'is_liked' => true // O withExists identificou o ID do usuário logado na tabela de likes!
        ]);
    }

    /**
     * Cenário 3: Tenta visualizar uma lista que não existe.
     * Deve disparar o fail do findOrFail e retornar 404.
     */
    public function test_retorna_404_quando_lista_nao_existe(): void
    {
        $idInexistente = 9999;

        $response = $this->getJson("/api/listas/{$idInexistente}");

        $response->assertStatus(404);
    }

    /**
     * Método Store
     * Cenário 1: Usuário autenticado cria uma lista completa.
     * Deve salvar dados, vincular tags, ordenar filmes na pivot e retornar 201.
     */
    public function test_usuario_autenticado_pode_criar_lista_com_tags_e_filmes_ordenados(): void
    {
        // 1. Preparar o cenário (Usuário e Filmes que serão vinculados)
        $user = User::factory()->create();

        $filmeA = Movie::factory()->create(['titulo_br' => 'Filme Alfa']);
        $filmeB = Movie::factory()->create(['titulo_br' => 'Filme Beta']);

        $payload = [
            'titulo' => 'Minha Lista Favorita',
            'comentario' => 'Uma lista bem estruturada',
            'slug' => 'minha-lista-favorita',
            'publica' => true,
            'tags' => ['Sci-Fi', 'Anos 90'],
            'movies' => [
                ['id' => $filmeB->id], // Index 0 (Deve ter ordem = 0)
                ['id' => $filmeA->id], // Index 1 (Deve ter ordem = 1)
            ]
        ];


        // 2. Executar a requisição POST (Usando Sanctum padrão para o Auth::user())
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/listas', $payload);

        // 3. Asserções do Response HTTP (201 Created)
        $response->assertStatus(201);
        $response->assertJsonPath('titulo', 'Minha Lista Favorita');
        $response->assertJsonPath('user_id', $user->id);

        // 4. Validar o Banco de Dados (Tabela Lists)
        $this->assertDatabaseHas('lists', [
            'titulo' => 'Minha Lista Favorita',
            'slug' => 'minha-lista-favorita',
            'user_id' => $user->id
        ]);

        // 5. Validar a tabela pivô de Filmes e a ORDEM correta
        // Nota: Substitua 'list_movie' pelo nome real da sua tabela pivô se for diferente
        $this->assertDatabaseHas('list_movie', [
            'movie_id' => $filmeB->id,
            'ordem' => 0
        ]);

        $this->assertDatabaseHas('list_movie', [
            'movie_id' => $filmeA->id,
            'ordem' => 1
        ]);

        // 6. Validar se a resposta JSON retornou os relacionamentos ordenados
        $response->assertJsonCount(2, 'movies');
        $response->assertJsonPath('movies.0.id', $filmeB->id); // O Filme B deve vir primeiro devido ao orderBy('ordem')
        $response->assertJsonPath('movies.1.id', $filmeA->id);
    }

    /**
     * Cenário 2: Erro de validação ao enviar dados incompletos.
     */
    public function test_falha_na_validacao_ao_tentar_criar_lista_sem_campos_obrigatorios(): void
    {
        $user = User::factory()->create();

        // Enviando payload vazio
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/listas', []);

        // Asserções
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['titulo', 'slug', 'publica']);
    }

    /**
     * Cenário 3: Ignorar silenciosamente filmes que não existem no banco de dados local.
     */
    public function test_ignora_vinculo_se_o_filme_enviado_nao_existir_no_banco(): void
    {
        $user = User::factory()->create();
        $idInexistente = 99999;

        $payload = [
            'titulo' => 'Lista com filme fantasma',
            'slug' => 'lista-filme-fantasma',
            'comentario' => 'Uma lista bem estruturada',
            'publica' => false,
            'movies' => [
                ['id' => $idInexistente]
            ]
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/listas', $payload);

        // Deve criar a lista normalmente ignorando o filme que não existe (201 Created)
        $response->assertStatus(201);

        // Garante que a tabela pivô ficou vazia
        $this->assertDatabaseEmpty('list_movie');
    }

    /**
     * Método Index
     * Cenário 1: Listagem padrão pública (sem filtros) para visitante anônimo.
     * Deve trazer apenas listas marcadas como 'publica' => true.
     */
    public function test_lista_apenas_listas_publicas_para_visitantes_deslogados(): void
    {
        // Cria uma lista pública e uma privada
        Lista::factory()->create(['titulo' => 'Lista Pública', 'publica' => true]);
        Lista::factory()->create(['titulo' => 'Lista Secreta', 'publica' => false]);

        $response = $this->getJson('/api/listas');

        $response->assertStatus(200);

        // Como é paginado, os dados ficam dentro da propriedade 'data'
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.titulo', 'Lista Pública');
    }

    /**
     * Cenário 2: Filtro 'user_only' trazendo apenas as listas do próprio usuário logado.
     */
    public function test_usuario_logado_pode_filtrar_apenas_suas_proprias_listas_inclusive_privadas(): void
    {
        $user = User::factory()->create();
        $outroUser = User::factory()->create();

        // Listas do usuário logado
        // Ao se cadastrar o usuário recebe duas listas default:
        /**
         *'titulo' => 'Assistir Mais Tarde','slug' => 'watch-later','is_default' => true, 'publica' => false
         *'titulo' => 'Assistidos','slug' => 'watched', 'is_default' => true,'publica' => false,
         */
        Lista::factory()->create(['titulo' => 'Minha Pública', 'user_id' => $user->id, 'publica' => true]);
        Lista::factory()->create(['titulo' => 'Minha Privada', 'user_id' => $user->id, 'publica' => false]);

        // Lista de outra pessoa
        Lista::factory()->create(['titulo' => 'Lista do Vizinho', 'user_id' => $outroUser->id, 'publica' => true]);

        // Faz a requisição enviando o parâmetro user_only=true
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/listas?user_only=true');
        $response->assertStatus(200);
        $response->assertJsonCount(4, 'data');
        $response->assertJsonPath('data.0.titulo', 'Assistir Mais Tarde');
        $response->assertJsonPath('data.1.titulo', 'Assistidos');
        $response->assertJsonPath('data.2.titulo', 'Minha Pública');
        $response->assertJsonPath('data.3.titulo', 'Minha Privada');
    }

    /**
     * Cenário 3: Filtro de busca textual (ILIKE) no título ou comentário.
     */
    public function test_pode_filtrar_listas_por_termo_de_busca(): void
    {
        Lista::factory()->create(['titulo' => 'Maratona Star Wars', 'publica' => true]);
        Lista::factory()->create(['titulo' => 'Documentários de História', 'publica' => true]);

        $response = $this->getJson('/api/listas?search=Star+Wars');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.titulo', 'Maratona Star Wars');
    }

    /**
     * Cenário 4: Filtro de quantidade mínima de likes (filterValue).
     */
    public function test_pode_filtrar_listas_por_quantidade_minima_de_likes(): void
    {
        $listaFamosa = Lista::factory()->create(['publica' => true]);
        $listaFlopada = Lista::factory()->create(['publica' => true]);

        // Vincula curtidas (Simula 3 likes na famosa e 0 na flopada)
        $usuarios = User::factory()->count(3)->create();
        $listaFamosa->likes()->attach($usuarios->pluck('id'));

        // Pede listas com no mínimo 2 likes
        $response = $this->getJson('/api/listas?filterValue=2');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $listaFamosa->id);
    }

    /**
     * Cenário 5: Ordenação pelo número de filmes anexados (orderBy=filmes).
     */
    public function test_pode_ordenar_listas_por_maior_quantidade_de_filmes(): void
    {
        $listaComMaisFilmes = Lista::factory()->create(['publica' => true]);
        $listaComMenosFilmes = Lista::factory()->create(['publica' => true]);

        $filmes = Movie::factory()->count(3)->create();

        // Anexa 3 filmes na primeira lista e apenas 1 na segunda
        $listaComMaisFilmes->movies()->attach([$filmes[0]->id, $filmes[1]->id, $filmes[2]->id]);
        $listaComMenosFilmes->movies()->attach([$filmes[0]->id]);

        $response = $this->getJson('/api/listas?orderBy=filmes');

        $response->assertStatus(200);

        // A lista com mais filmes deve vir primeiro no índice 0
        $response->assertJsonPath('data.0.id', $listaComMaisFilmes->id);
        $response->assertJsonPath('data.1.id', $listaComMenosFilmes->id);
    }

    /**
     * Cenário 6: Limitação máxima de 4 filmes no relacionamento de retorno do index.
     * Garante que o select e o limit(4) do relacionamento funcionaram.
     */
    public function test_relacionamento_de_movies_traz_no_maximo_quatro_filmes(): void
    {
        $listaGrande = Lista::factory()->create(['publica' => true]);

        // Criar 6 filmes e anexar à lista
        $filmes = Movie::factory()->count(6)->create();
        $listaGrande->movies()->attach($filmes->pluck('id')->toArray());

        $response = $this->getJson('/api/listas');

        $response->assertStatus(200);

        // O movies_count deve registrar o total real do banco (6)
        $response->assertJsonPath('data.0.movies_count', 6);

        // Mas a coleção de dados carregada pelo 'with' deve conter estritamente no máximo 4 itens
        $this->assertLessThanOrEqual(4, count($response->json('data.0.movies')));
    }
    /**
     * Método Update
     * Cenário 1: O criador/dono da lista pode atualizá-la com sucesso.
     * Deve testar a reordenação correta dos filmes na tabela pivô.
     */
    public function test_dono_da_lista_pode_atualizar_dados_e_reordenar_filmes(): void
    {
        $dono = User::factory()->create();

        // Criamos uma lista vinculada a este usuário
        $lista = Lista::factory()->create([
            'titulo' => 'Título Antigo',
            'user_id' => $dono->id,
            'publica' => true
        ]);

        $filmeA = Movie::factory()->create();
        $filmeB = Movie::factory()->create();

        // Inicialmente a lista tem os dois filmes inseridos em uma ordem qualquer
        $lista->movies()->attach([$filmeA->id => ['ordem' => 0], $filmeB->id => ['ordem' => 1]]);

        // Payload alterando o título e INVERTENDO a ordem dos filmes
        $payload = [
            'titulo' => 'Título Novo Atualizado',
            'publica' => false,
            'movies' => [
                $filmeB->id, // Agora o Filme B vem primeiro (deve ganhar ordem = 0)
                $filmeA->id  // O Filme A vem depois (deve ganhar ordem = 1)
            ]
        ];

        $response = $this->actingAs($dono, 'sanctum')
            ->putJson("/api/listas/{$lista->id}", $payload);

        // Asserções da resposta
        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Lista atualizada com sucesso');
        $response->assertJsonPath('data.titulo', 'Título Novo Atualizado');

        // Validar no banco de dados se os dados básicos mudaram (tabela 'lists')
        $this->assertDatabaseHas('lists', [
            'id' => $lista->id,
            'titulo' => 'Título Novo Atualizado',
            'publica' => false
        ]);

        // Validar se a tabela pivô atualizou as ordens usando a nova sequência
        $this->assertDatabaseHas('list_movie', [
            'list_id' => $lista->id,
            'movie_id' => $filmeB->id,
            'ordem' => 0
        ]);

        $this->assertDatabaseHas('list_movie', [
            'list_id' => $lista->id,
            'movie_id' => $filmeA->id,
            'ordem' => 1
        ]);
    }

    /**
     * Cenário 2: Um Administrador pode atualizar a lista de qualquer outra pessoa.
     */
    public function test_administrador_pode_atualizar_lista_de_outro_usuario(): void
    {
        // Cria a role admin do Spatie
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $outroUsuario = User::factory()->create();
        $lista = Lista::factory()->create(['user_id' => $outroUsuario->id, 'titulo' => 'Lista do Usuário Comum']);

        $payload = [
            'titulo' => 'Moderado por Admin'
        ];

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/listas/{$lista->id}", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('lists', [
            'id' => $lista->id,
            'titulo' => 'Moderado por Admin'
        ]);
    }

    /**
     * Cenário 3: Um usuário comum tenta editar a lista de outro e é bloqueado.
     */
    public function test_usuario_comum_nao_pode_atualizar_lista_de_terceiros(): void
    {
        $donoReal = User::factory()->create();
        $invasor = User::factory()->create();

        $lista = Lista::factory()->create(['user_id' => $donoReal->id, 'titulo' => 'Lista Original']);

        $payload = [
            'titulo' => 'Tentativa de Hack'
        ];

        $response = $this->actingAs($invasor, 'sanctum')
            ->putJson("/api/listas/{$lista->id}", $payload);

        // Asserção de Não Autorizado (403)
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Não autorizado');

        // Garante que o banco CONTINUA com o título antigo intacto
        $this->assertDatabaseHas('lists', [
            'id' => $lista->id,
            'titulo' => 'Lista Original'
        ]);
    }

    /**
     * Cenário 4: Falha na validação ao tentar enviar um filme inexistente no banco.
     */
    public function test_falha_na_validacao_ao_passar_id_de_filme_inexistente(): void
    {
        $dono = User::factory()->create();
        $lista = Lista::factory()->create(['user_id' => $dono->id]);

        $idInexistente = 99999;

        $payload = [
            'movies' => [$idInexistente]
        ];

        $response = $this->actingAs($dono, 'sanctum')
            ->putJson("/api/listas/{$lista->id}", $payload);

        // Asserções de erro de validação (422)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['movies.0']);
    }
    /**
     * Método Destroy
     * Cenário 1: O criador/dono da lista pode deletá-la com sucesso.
     */
    public function test_dono_da_lista_pode_apagar_lista(): void
    {
        $dono = User::factory()->create();

        // Criamos uma lista vinculada a este usuário
        $lista = Lista::factory()->create([
            'titulo' => 'Lista a Apagar',
            'user_id' => $dono->id,
            'publica' => true
        ]);

        $response = $this->actingAs($dono, 'sanctum')
            ->delete("/api/listas/{$lista->id}");

        // Asserções da resposta
        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Lista removida');

        // Validar no banco de dados se os dados básicos mudaram (tabela 'lists')
        $this->assertDatabaseMissing('lists', [
            'id' => $lista->id,
        ]);
    }
    /**
     * Método Destroy
     * Cenário 2: O Administrador pode deletar lista com sucesso.
     */
    public function test_administrador_pode_deletar_lista_de_outro_usuario(): void
    {
        // Cria a role admin do Spatie
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $outroUsuario = User::factory()->create();
        $lista = Lista::factory()->create(['user_id' => $outroUsuario->id, 'titulo' => 'Lista do Usuário Comum']);

        $response = $this->actingAs($admin, 'sanctum')
            ->delete("/api/listas/{$lista->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('lists',
            ['id' => $lista->id]
        );
    }

    public function test_usuario_comum_nao_pode_deletar_lista_de_terceiros(): void
    {
        $donoReal = User::factory()->create();
        $invasor = User::factory()->create();

        $lista = Lista::factory()->create(['user_id' => $donoReal->id, 'titulo' => 'Lista Original']);

        $response = $this->actingAs($invasor, 'sanctum')
            ->delete("/api/listas/{$lista->id}");

        // Asserção de Não Autorizado (403)
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Não autorizado');

        // Garante que o banco CONTINUA com a lista
        $this->assertDatabaseHas('lists', [
            'id' => $lista->id
        ]);
    }

    public function test_retorna_is_liked_como_true_se_o_usuario_autenticado_curtiu_a_lista_toggle_like(): void
    {
        $userDonoLista = User::factory()->create();
        dump('dono review', $userDonoLista->id);
        $userLogado = User::factory()->create();
        $userLogado1 = User::factory()->create();
        $lista = Lista::factory()->create(['user_id' => $userDonoLista->id]);
        
        // O usuário logado curte a review através do método toggleLike
        $responseToggleLike = $this->actingAs($userLogado, 'sanctum')->post("api/listas/{$lista->id}/like");
        $responseToggleLike->assertStatus(200);
        $responseToggleLike->assertJsonPath('is_liked', true);
        $responseToggleLike->assertJsonPath('message', "Like adicionado");

        $responseToggleLike1 = $this->actingAs($userLogado1, 'sanctum')->post("api/listas/{$lista->id}/like");
        $responseToggleLike1->assertStatus(200);
        $responseToggleLike1->assertJsonPath('is_liked', true);
        $responseToggleLike1->assertJsonPath('message', "Like adicionado");
        $responseToggleLike1->assertJsonPath('likes_count', 2);
        
        $response = $this->actingAs($userLogado, 'sanctum')
            ->get('/api/listas');

        $response->assertStatus(200);
            
        $response->dump();
        
        // Como o usuário curtiu, o withExists deve marcar como true
        $response->assertJsonPath('data.0.is_liked', true); // erro retorna false
        $response->assertJsonPath('data.0.likes_count', 2); // erro retorna 0
    }
}
