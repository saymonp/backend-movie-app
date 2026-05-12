<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Genero;
use App\Models\Diretor;
use App\Models\Colecao;
use Illuminate\Http\Request;
use App\Jobs\StoreMovieJob;
use Illuminate\Support\Facades\Artisan;
use App\Services\TmdbService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MovieController extends Controller
{
    /**
     * Lista os filmes com paginação e gêneros carregados (Eager Loading).
     */
    public function index(Request $request)
    {
        $lang = $request->input('lang', 'pt-BR');
        // Iniciamos a query com os relacionamentos básicos para evitar o problema N+1
        $query = Movie::with(['generos', 'diretores']);

        // 1. Pesquisa Global por Texto (Título, Tagline, Descrição)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('titulo_br', 'ILIKE', "%{$search}%")
                    ->orWhere('titulo_en', 'ILIKE', "%{$search}%")
                    ->orWhere('titulo_original', 'ILIKE', "%{$search}%")
                    ->orWhere('tagline_br', 'ILIKE', "%{$search}%")
                    ->orWhere('tagline_en', 'ILIKE', "%{$search}%")
                    ->orWhere('descricao_br', 'ILIKE', "%{$search}%");
            });
        }

        // 2. Filtro por Gêneros (Checkbox - Array de IDs)
        if ($request->filled('generos')) {
            $genres = (array) $request->input('generos');
            $query->whereHas('generos', function ($q) use ($genres) {
                $q->whereIn('generos.id', $genres);
            });
        }

        // 3. Filtro por Ano de Lançamento
        if ($request->filled('ano')) {
            $query->whereYear('release_date', $request->input('ano'));
        }

        // 4. Filtro por Diretor (Array de IDs ou Nome)
        if ($request->filled('diretores')) {
            $diretores = (array) $request->input('diretores');
            $query->whereHas('diretores', function ($q) use ($diretores) {
                $q->whereIn('diretores.id', $diretores);
            });
        }

        // 5. Filtro por Idioma (lingua_origem)
        if ($request->filled('idioma')) {
            $query->where('lingua_origem', $request->input('idioma'));
        }

        // --- BOTÕES ESPECÍFICOS ---

        // 6. Botão Destaque (Baseado na métrica de popularidade)
        if ($request->boolean('destaque')) {
            $query->orderBy('popularity', 'desc');
        }

        if ($request->boolean('recentes')) {
            // Filtra filmes lançados do dia de hoje até 6 meses atrás
            $query->where('release_date', '>=', now()->subMonths(6))
                ->orderBy('release_date', 'desc');
        }

        // 8. Maiores Bilheterias (Ordenação)
        if ($request->boolean('bilheteria')) {
            // Supondo que você tenha a coluna 'revenue' ou 'popularity'
            $query->orderBy('revenue', 'desc');
        } else {
            // Ordenação padrão por data de lançamento
            $query->orderBy('release_date', 'desc');
        }

        // Paginação com 24 itens (bom para grids de 2, 3, 4 ou 6 colunas)
        $movies = $query->paginate(24)->withQueryString();

        // Lógica de Busca por Demanda
        if ($movies->isEmpty() && $request->filled('search')) {
            return $this->handleMovieNotFound($search, $lang);
        }

        return response()->json($movies);
    }

    public function indexAddToList(Request $request)
    {
        $lang = $request->input('lang', 'pt-BR');
        $search = $request->input('search');

        // Iniciamos a query selecionando apenas os campos necessários do filme
        $query = Movie::query()->select([
            'id',
            'titulo_original',
            'titulo_br',
            'titulo_en',
            'release_date',
            'rating',
            'poster_thumb_br',
            'poster_thumb_us'
        ]);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($search) {
                // Pesquisa nos campos da tabela Movies
                $q->where('titulo_br', 'ILIKE', "%{$search}%")
                    ->orWhere('titulo_en', 'ILIKE', "%{$search}%")
                    ->orWhere('titulo_original', 'ILIKE', "%{$search}%")
                    ->orWhere('tagline_br', 'ILIKE', "%{$search}%")
                    ->orWhere('descricao_br', 'ILIKE', "%{$search}%");

                // PESQUISA NA TABELA RELACIONADA (diretores)
                $q->orWhereHas('diretores', function ($queryDiretor) use ($search) {
                    $queryDiretor->where('nome', 'ILIKE', "%{$search}%");
                });
            });
        }

        // Carrega o relacionamento, limita e executa
        $movies = $query->with('diretores')
            ->limit(10)
            ->get();

        // Lógica de Busca por Demanda
        if ($movies->isEmpty() && $request->filled('search')) {
            return $this->handleMovieNotFound($search, $lang);
        }

        return response()->json($movies);
    }

    protected function handleMovieNotFound($search, $lang)
    {
        // 1. Pesquisa rápida no TMDB (apenas para validar se existe)
        $tmdb = new TmdbService();

        $tmdbResults = $tmdb->searchMovie($search, $lang);

        if (!empty($tmdbResults)) {
            // Pegamos o ID do primeiro resultado relevante

            $tmdbId = $tmdbResults[0]['id'];
            $temp_slug = (string)rand(1, 999);
            $movieRecord = Movie::firstOrCreate(
                ['tmdb_id' => $tmdbId], // Busca por isso
                ['titulo_original' => $tmdbResults[0]['original_title'], 'slug_pt' => $temp_slug, 'slug_en' => $temp_slug, 'status' => 'processando'] // Se não achar, cria com isso
            );
            // 2. Dispara o Job de alta prioridade
            // onQueue('high') permite que esse job passe na frente de outros
            StoreMovieJob::dispatch(['tmdb_id' => $tmdbId])
                ->onQueue('high');

            return response()->json([
                'message' => 'Filme não encontrado localmente, mas localizado no TMDB. Estamos importando agora!',
                'temp_result' => $tmdbResults[0], // Opcional: envia os dados básicos para o front exibir um "loading"
                'status' => 'processando',
                'id' => $movieRecord->id,
                'tmdb_id' => $tmdbResults[0]['id'],
            ], 202);
        }

        return response()->json(['message' => 'Nenhum filme encontrado.'], 404);
    }

    /**
     * Exibe um filme específico com todos os seus relacionamentos.
     */
    public function show($slug)
    {
        // 1. Extrai o ID da URL (ex: 235-slug-do-filme)
        $id = explode('-', $slug)[0];

        // 2. Carrega o filme com as relações básicas
        $movie = Movie::with(['generos', 'diretores', 'estudios', 'paises', 'colecao'])
            ->findOrFail($id);

        $collectionMovies = collect();

        if ($movie->colecao_id) {
            // 3. Tenta buscar filmes da coleção no banco local (exceto o próprio filme atual)
            $collectionMovies = Movie::where('colecao_id', $movie->colecao_id)
                ->where('id', '!=', $movie->id)
                ->select('id', 'titulo_br', 'titulo_en', 'poster_thumb_br', 'rating', 'tmdb_id', 'slug_pt', 'slug_en')
                ->get();

            // 4. Se não houver outros filmes da coleção no banco, busca no TMDB
            if ($collectionMovies->isEmpty() && $movie->colecao->tmdb_id) {
                $tmdb = new TmdbService();
                $tmdbResults = $tmdb->getCollectionDetails($movie->colecao->tmdb_id); // Deve retornar o array 'parts'

                $collectionMovies = collect($tmdbResults)->map(function ($item) use ($movie) {
                    // Pula o filme atual na listagem da coleção
                    if ($item['id'] == $movie->tmdb_id) return null;

                    // Cria o registro básico para permitir que o usuário interaja/clique
                    $tempSlug = (string)rand(1000, 9999);
                    $movieRecord = Movie::firstOrCreate(
                        ['tmdb_id' => $item['id']],
                        [
                            'titulo_original' => $item['original_title'] ?? ($item['title'] ?? ''),
                            'titulo_br' => $item['title'] ?? '',
                            'poster_thumb_br' => $item['poster_path'] ?? null,
                            'rating' => $item['vote_average'] ?? 0,
                            'colecao_id' => $movie->colecao_id, // Vincula à coleção existente
                            'status' => 'processando',
                            'slug_pt' => $tempSlug,
                            'slug_en' => $tempSlug
                        ]
                    );

                    // Dispara o Job para cada filme da coleção que não está completo
                    StoreMovieJob::dispatch(['tmdb_id' => $item['id']])->onQueue('high');

                    return [
                        'id' => $movieRecord->id,
                        'title' => $item['title'] ?? '',
                        'poster' => $item['poster_path'] ? "https://image.tmdb.org/t/p/w500" . $item['poster_path'] : null,
                        'rating' => $item['vote_average'] ?? 0,
                        'status' => 'processando'
                    ];
                })->filter()->values();
            }
        }

        // Adiciona a lista formatada ao objeto de resposta
        return response()->json([
            'movie' => $movie,
            'collection' => $collectionMovies
        ]);
    }
    public function showFull($slug)
    {
        // 1. Extrai o ID da URL
        $id = explode('-', $slug)[0];

        // 2. Carrega o filme com relações essenciais
        // Usamos o ID do usuário (opcional) para já trazer se ele deu "like" nas reviews
        $userId = auth('sanctum')->id();

        $movie = Movie::with(['generos', 'diretores', 'estudios', 'paises', 'colecao'])
            ->findOrFail($id);

        // --- 3. COLEÇÃO (Lógica que você já tinha) ---
        $collectionMovies = collect();
        if ($movie->colecao_id) {
            $collectionMovies = Movie::where('colecao_id', $movie->colecao_id)
                ->where('id', '!=', $movie->id)
                ->select('id', 'titulo_br', 'titulo_en', 'poster_thumb_br', 'rating', 'slug_pt', 'slug_en')
                ->get();

            // 4. Se não houver outros filmes da coleção no banco, busca no TMDB
            if ($collectionMovies->isEmpty() && $movie->colecao->tmdb_id) {
                $tmdb = new TmdbService();
                $tmdbResults = $tmdb->getCollectionDetails($movie->colecao->tmdb_id); // Deve retornar o array 'parts'

                $collectionMovies = collect($tmdbResults)->map(function ($item) use ($movie) {
                    // Pula o filme atual na listagem da coleção
                    if ($item['id'] == $movie->tmdb_id) return null;

                    // Cria o registro básico para permitir que o usuário interaja/clique
                    $tempSlug = (string)rand(1000, 9999);
                    $movieRecord = Movie::firstOrCreate(
                        ['tmdb_id' => $item['id']],
                        [
                            'titulo_original' => $item['original_title'] ?? ($item['title'] ?? ''),
                            'titulo_br' => $item['title'] ?? '',
                            'poster_thumb_br' => $item['poster_path'] ?? null,
                            'rating' => $item['vote_average'] ?? 0,
                            'colecao_id' => $movie->colecao_id, // Vincula à coleção existente
                            'status' => 'processando',
                            'slug_pt' => $tempSlug,
                            'slug_en' => $tempSlug
                        ]
                    );

                    // Dispara o Job para cada filme da coleção que não está completo
                    StoreMovieJob::dispatch(['tmdb_id' => $item['id']])->onQueue('high');

                    return [
                        'id' => $movieRecord->id,
                        'title' => $item['title'] ?? '',
                        'poster' => $item['poster_path'] ? "https://image.tmdb.org/t/p/w500" . $item['poster_path'] : null,
                        'rating' => $item['vote_average'] ?? 0,
                        'status' => 'processando'
                    ];
                })->filter()->values();
            }
        }

        // --- 4. FILMES RELACIONADOS (Mesma "pegada") ---
        // Busca filmes que compartilham os mesmos gêneros, excluindo o atual
        $generoIds = $movie->generos->pluck('id');
        $relatedMovies = Movie::whereHas('generos', function ($q) use ($generoIds) {
            $q->whereIn('generos.id', $generoIds);
        })
            ->where('id', '!=', $movie->id)
            ->where('status', 'processado')
            ->select('id', 'titulo_br', 'titulo_original', 'poster_thumb_br', 'rating', 'slug_pt', 'slug_en')
            ->orderBy('rating', 'desc')
            ->limit(12)
            ->get();

        // --- 5. LISTAS RELACIONADAS ---
        // Busca listas que contenham o filme atual ou que tenham o nome do filme no título/descrição
        $relatedLists = \App\Models\Lista::whereHas('movies', function ($q) use ($movie) {
            $q->where('movies.id', $movie->id);
        })
            ->orWhere('titulo', 'like', "%{$movie->titulo_br}%")
            ->with(['movies' => function ($q) {
                $q->select('movies.id', 'poster_thumb_br')->limit(4); // Pegamos só 4 posters para o estilo visual
            }])
            ->limit(8)
            ->get();

        // --- 6. REVIEWS POPULARES ---
        // Pegamos as reviews mais recentes/curtidas (conforme sua lógica de reviews públicas)
        $reviews = \App\Models\Review::where('movie_id', $movie->id)
            ->with(['user:id,name,avatar', 'tags:id,nome'])
            ->withCount('likes')
            ->withExists(['likes as is_liked' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->orderBy('likes_count', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // 7. Resposta Única (O "Big Object")
        return response()->json([
            'movie'       => $movie,
            'collection'  => $collectionMovies,
            'related'     => $relatedMovies,
            'lists'       => $relatedLists,
            'reviews'     => $reviews,
        ]);
    }
    /**
     * Salva um novo filme e sincroniza os relacionamentos pivot.
     */
    public function store(Request $request, $tmdb_id)
    {
        if ($request->user()->can('import movies')) {
            // 1. Verificação de existência (Padrão 409 Conflict)
            if (Movie::where('tmdb_id', $tmdb_id)->exists()) {
                $message = 'Este filme já existe no catálogo e será atualizado em processamento prioritário';
            } else {
                $message = 'O filme foi enviado para processamento prioritário.';
            }
            // 2. Despacho com ALTA PRIORIDADE
            // método onQueue('high')
            StoreMovieJob::dispatch(['tmdb_id' => $tmdb_id])
                ->onQueue('high');

            // 202 (Aceito para processamento)
            return response()->json([
                'message' => $message,
                'data' => [
                    'tmdb_id' => $tmdb_id
                ]
            ], 202);
        }
        return response()->json(['message' => 'Não autorizado'], 403);
    }

    /**
     * Atualiza os dados do filme e as tabelas pivot.
     */
    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $movie = Movie::findOrFail($id);

            $movie->update($request->all());

            // O método sync remove o que não está no array e adiciona o que é novo
            if ($request->has('generos')) $movie->generos()->sync($request->generos);
            if ($request->has('diretores')) $movie->diretores()->sync($request->diretores);
            if ($request->has('paises')) $movie->paises()->sync($request->paises);
            if ($request->has('estudios')) $movie->estudios()->sync($request->estudios);

            return response()->json($movie->fresh(['generos', 'diretores']));
        });
    }

    /**
     * Remove o filme. 
     * Nota: O ON DELETE CASCADE no seu SQL já limpa as tabelas pivot automaticamente.
     */
    public function destroy(Request $request, $id)
    {
        // O Laravel verifica automaticamente se o usuário autenticado via Sanctum tem a permissão
        if ($request->user()->can('delete movies')) {
            $movie = Movie::findOrFail($id);
            $movie->delete();
            return response()->json(['message' => 'Filme removido com sucesso'], 200);
        }

        return response()->json(['message' => 'Não autorizado'], 403);
    }

    public function importMovies(Request $request, $limit)
    {
        if ($request->user()->can('import movies')) {

            // Executa o comando: php artisan import:movies {limit}
            // Usamos queue para não travar a requisição HTTP
            Artisan::queue('import:movies', [
                'limit' => $limit
            ]);

            return response()->json([
                'message' => "O processo de importação de {$limit} filmes foi iniciado em segundo plano.",
            ], 202);
        }

        return response()->json(['message' => 'Não autorizado'], 403);
    }

    public function indexGenres()
    {
        $generos = Genero::all();

        return response()->json($generos);
    }

    public function indexDirectors()
    {
        $diretores = Diretor::all();

        return response()->json($diretores);
    }

    public function getIdioms()
    {
        // Obtém todos os valores únicos da coluna 'lingua_origem'
        // Onde o valor não é nulo e ordena alfabeticamente
        $idiomas = Movie::query()
            ->select('lingua_origem')
            ->whereNotNull('lingua_origem')
            ->distinct()
            ->orderBy('lingua_origem', 'asc')
            ->pluck('lingua_origem');

        return response()->json($idiomas);
    }
}
