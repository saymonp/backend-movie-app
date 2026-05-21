<?php

namespace Tests\Feature;

use App\Jobs\ProcessMovieImagesJob;
use App\Jobs\ProcessMovieTranslationJob;
use App\Jobs\StoreMovieJob;
use App\Models\Movie;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StoreMovieJob::class)]
class StoreMovieJobTest extends TestCase
{
    use RefreshDatabase;

    private array $dadosMocadosDoTmdb;

    protected function setUp(): void
    {
        parent::setUp();

        // Massa de dados fake padrão simulando o retorno perfeito validado do TmdbService
        $this->dadosMocadosDoTmdb = [
            'tmdb_id'         => 550,
            'imdb_id'         => 'tt0137523',
            'titulo_original' => 'Fight Club',
            'titulo_br'       => 'Clube de Combate',
            'titulo_en'       => 'Fight Club',
            'tagline_br'      => 'Má conduta. Caos. Sabão.',
            'tagline_en'      => 'Mischief. Mayhem. Soap.',
            'descricao_br'    => 'Um homem deprimido sofrendo de insônia...',
            'descricao_en'    => 'An insomniac office worker and a devil-may-care soap maker...',
            'slug_pt'         => 'clube-da-luta',
            'slug_en'         => 'fight-club',
            'rating'          => 8.4,
            'duracao'         => 139,
            'lingua_origem'   => 'en',
            'release_date'    => '1999-10-15',
            'homepage'        => 'https://www.foxmovies.com',
            'generos'         => [
                [
                    'tmdb_id' => 18,
                    'nome_pt' => 'Drama',
                    'nome_en' => 'Drama'
                ]
            ],
            'diretores'       => [
                'David Fincher'
            ],
            'estudios'        => ['20th Century Fox'],
            'paises'          => ['US'],
            'revenue'         => 100853753,
            'popularity'      => 65.43,
            'status'          => 'Released',
            'poster_path_br'  => 'https://image.tmdb.org/t/p/w500/path.jpg',
            'poster_thumb_br' => 'https://image.tmdb.org/t/p/w185/path.jpg',
            'backdrop_path'   => 'https://image.tmdb.org/t/p/w1280/back.jpg',
            'poster_path_us'  => 'https://image.tmdb.org/t/p/w500/path_us.jpg',
            'poster_thumb_us' => 'https://image.tmdb.org/t/p/w185/path_us.jpg',
        ];
    }

    /**
     * Cenário 1: Caminho feliz. O filme é baixado com sucesso, persistido no banco 
     * com status 'processado', e os sub-jobs de imagem são despachados.
     */
    public function test_deve_processar_e_salvar_filme_com_sucesso_e_despachar_job_de_imagens(): void
    {
        Queue::fake([ProcessMovieImagesJob::class, ProcessMovieTranslationJob::class]);

        // Mockamos o TmdbService para interceptar a instância criada com 'new' dentro do Job
        $this->mock(TmdbService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getMovieGeneros')->once()->andReturn([18 => 'Drama']);
            $mock->shouldReceive('getMovieDetails')
                ->once()
                ->with(550, [18 => 'Drama'])
                ->andReturn($this->dadosMocadosDoTmdb);
        });

        // Instancia e executa o Job manualmente
        $job = new StoreMovieJob(['tmdb_id' => 550]);
        $job->handle();

        // Asserções no Banco de Dados
        $this->assertDatabaseHas('movies', [
            'tmdb_id' => 550,
            'titulo_br' => 'Clube de Combate',
            'status' => 'processado'
        ]);

        $movie = Movie::where('tmdb_id', 550)->first();

        // Garante que o Job de Imagens foi para a fila correta ('images') com os dados certos
        Queue::assertPushed(ProcessMovieImagesJob::class, function ($job, $queue) use ($movie) {
            // $queue carrega exatamente o nome da fila onde o job foi empurrado
            return $queue === 'images' && $job->movie->id === $movie->id;
        });

        // Como o filme já veio preenchido com 'descricao_br', o Job de Tradução NÃO deve ser chamado
        Queue::assertNotPushed(ProcessMovieTranslationJob::class);
    }

    /**
     * Cenário 2: O TMDB falha (retorna nulo).
     * O job deve apagar qualquer registro fantasma e disparar uma exceção.
     */
    public function test_deve_remover_filme_fantasma_e_lancar_excecao_se_tmdb_falhar(): void
    {
        // Simula que um registro que começou a processar ficou "preso" no banco antes da falha
        $ghostMovie = Movie::factory()->create([
            'tmdb_id' => 550,
            'status' => 'processando',
            'titulo_original' => ''
        ]);

        $this->mock(TmdbService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getMovieGeneros')->andReturn([18 => 'Drama']);
            $mock->shouldReceive('getMovieDetails')->andReturn(null); // TMDB caiu
        });

        $job = new StoreMovieJob(['tmdb_id' => 550]);

        // O Job precisa falhar lançando uma Exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('TMDB não retornou dados para o ID: 550');

        $job->handle();

        // Garante que o registro incompleto foi deletado pelo bloco de catch do seu código
        $this->assertDatabaseMissing('movies', ['id' => $ghostMovie->id]);
    }

    /**
     * Cenário 3: Método failed() acionado (Exaustão de tentativas ou erro crítico).
     * Deve efetuar a limpeza de registros com status inválido ou sem título original.
     */
    public function test_metodo_failed_limpa_registro_incompleto_da_base(): void
    {
        // Cria um filme corrompido que travou no status de processamento
        $corruptedMovie = Movie::factory()->create([
            'tmdb_id' => 999,
            'status' => 'processando',
            'titulo_original' => ''
        ]);

        $job = new StoreMovieJob(['tmdb_id' => 999]);

        // Simula o disparo do método de falha definitiva do Laravel Queue
        $job->failed(new \Exception("Erro forçado de timeout de fila"));

        // O banco precisa ser limpo para não guardar lixo
        $this->assertDatabaseMissing('movies', ['id' => $corruptedMovie->id]);
    }
}
