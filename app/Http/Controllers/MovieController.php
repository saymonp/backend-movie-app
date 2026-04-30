<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use App\Jobs\StoreMovieJob;
use Illuminate\Support\Facades\Artisan;
use App\Services\TmdbService;

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

    protected function handleMovieNotFound($search, $lang)
    {
        // 1. Pesquisa rápida no TMDB (apenas para validar se existe)
        $tmdb = new TmdbService();

        $tmdbResults = $tmdb->searchMovie($search, $lang);

        if (!empty($tmdbResults)) {
            // Pegamos o ID do primeiro resultado relevante
            $tmdbId = $tmdbResults[0]['id'];

            // 2. Dispara o Job de alta prioridade
            // onQueue('high') permite que esse job passe na frente de outros
            StoreMovieJob::dispatch(['tmdb_id' => $tmdbId])
                ->onQueue('high');

            return response()->json([
                'message' => 'Filme não encontrado localmente, mas localizado no TMDB. Estamos importando agora!',
                'temp_result' => $tmdbResults[0], // Opcional: envia os dados básicos para o front exibir um "loading"
                'status' => 'importing'
            ], 202);
        }

        return response()->json(['message' => 'Nenhum filme encontrado.'], 404);
    }

    /**
     * Exibe um filme específico com todos os seus relacionamentos.
     */
    public function show($id)
    {
        $movie = Movie::with(['generos', 'diretores', 'estudios', 'paises', 'colecao'])
            ->findOrFail($id);

        return response()->json($movie);
    }

    /**
     * Salva um novo filme e sincroniza os relacionamentos pivot.
     */
    public function store(Request $request, $tmdb_id)
    {
        if ($request->user()->can('import movies')) {
            // 1. Verificação de existência (Padrão 409 Conflict)
            if (Movie::where('tmdb_id', $tmdb_id)->exists()) {
                return response()->json([
                    'message' => 'Este filme já existe no catálogo.',
                    'status'  => 'error'
                ], 409);
            }

            // 2. Despacho com ALTA PRIORIDADE
            // método onQueue('high')
            StoreMovieJob::dispatch(['tmdb_id' => $tmdb_id])
                ->onQueue('high');

            // 202 (Aceito para processamento)
            return response()->json([
                'message' => 'O filme foi enviado para processamento prioritário.',
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
        $movie = Movie::findOrFail($id);

        $movie->update($request->all());

        // O método sync remove o que não está no array e adiciona o que é novo
        if ($request->has('generos')) $movie->generos()->sync($request->generos);
        if ($request->has('diretores')) $movie->diretores()->sync($request->diretores);
        if ($request->has('paises')) $movie->paises()->sync($request->paises);
        if ($request->has('estudios')) $movie->estudios()->sync($request->estudios);

        return response()->json($movie->fresh(['generos', 'diretores']));
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
            return response()->json(['message' => 'Filme removido com sucesso'], 204);
        }

        return response()->json(['message' => 'Não autorizado'], 403);
    }

    public function importMovies(Request $request, $limit)
    {
        if ($request->user()->can('import movies')) {

            // Executa o comando: php artisan import:movies {amount}
            // Usamos queue para não travar a requisição HTTP
            Artisan::queue('import:movies', [
                'amount' => $limit
            ]);

            return response()->json([
                'message' => "O processo de importação de {$limit} filmes foi iniciado em segundo plano.",
            ], 202);
        }

        return response()->json(['message' => 'Não autorizado'], 403);
    }
}
