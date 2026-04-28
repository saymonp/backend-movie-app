<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\TmdbService;
use App\Jobs\StoreMovieJob;

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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tmdb_id' => 'required|integer|unique:movies,tmdb_id'
        ]);

        // 2. Verificação de existência (Padrão 409 Conflict)
        if (Movie::where('tmdb_id', $validated['tmdb_id'])->exists()) {
            return response()->json([
                'message' => 'Este filme já existe no catálogo.',
                'status'  => 'error'
            ], 409);
        }

        // 3. Despacho com ALTA PRIORIDADE
        // método onQueue('high')
        StoreMovieJob::dispatch($validated)
            ->onQueue('high');

        // 202 (Aceito para processamento)
        return response()->json([
            'message' => 'O filme foi enviado para processamento prioritário.',
            'data' => [
                'tmdb_id' => $validated['tmdb_id']
            ]
        ], 202);
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
}
