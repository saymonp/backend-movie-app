<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use App\Jobs\StoreMovieJob;
use Illuminate\Support\Facades\Artisan;

class MovieController extends Controller
{
    /**
     * Lista os filmes com paginação e gêneros carregados (Eager Loading).
     */
    public function index()
    {
        $movies = Movie::with(['generos', 'colecao'])->paginate(15);
        return response()->json($movies);
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
