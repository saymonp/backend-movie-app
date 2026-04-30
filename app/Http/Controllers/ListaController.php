<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lista;
use Illuminate\Support\Facades\Auth;

class ListaController extends Controller
{
    public function show($id)
    {
        $userId = Auth::id();

        $lista = Lista::with(['user', 'tags', 'movies'])
            ->withCount('likes')
            ->withExists(['likes as is_liked' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->findOrFail($id);

        return response()->json($lista);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'titulo' => 'nullable|string',
            'comentario' => 'nullable|string',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'movies' => 'array',
            'movies.*' => 'exists:movies,id'
        ]);

        // 1. Pegar o ID do usuário logado pelo Token
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::user();
        $dados['user_id'] = $currentUser->id;

        // 3. Criar a lista
        $lista = Lista::create($dados);

        // 4. Sincronizar as tags na tabela pivot
        if ($request->has('tags')) {
            $lista->tags()->sync($dados['tags']);
        }

        // 5. Sincronizar filmes
        if ($request->has('movies')) {
            $lista->movies()->sync($dados['movies']);
        }

        return response()->json($lista->load(['tags', 'user', 'movies']), 201);
    }

    public function index()
    {
        $userId = Auth::id();
        $listas = Lista::with(['user', 'tags', 'movies'])
            ->withCount('likes')
            ->withExists(['likes as is_liked' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->latest()
            ->paginate(10);

        return response()->json($listas);
    }

    public function update(Request $request, $id)
    {
        $lista = Lista::findOrFail($id);

        // Segurança: Só o dono da lista ou um Admin pode editar
        if ($request->user()->id !== $lista->user_id && !$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $dados = $request->validate([
            'titulo' => 'nullable|string',
            'comentario' => 'nullable|string',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'movies' => 'array',
            'movies.*' => 'exists:movies,id'
        ]);

        $lista->update($dados);

        // Atualiza as tags na pivot
        if ($request->has('tags')) {
            $lista->tags()->sync($dados['tags']);
        }

        // Atualiza os filmes na pivot
        if ($request->has('movies')) {
            $lista->movies()->sync($dados['movies']);
        }

        return response()->json([
            'message' => 'Lista atualizada com sucesso',
            'data' => $lista->load(['tags', 'user', 'movies'])
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $lista = Lista::findOrFail($id);

        // Segurança: Só o dono ou Admin pode deletar
        if ($request->user()->id !== $lista->user_id && !$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $lista->delete();

        return response()->json(['message' => 'Lista removida'], 200);
    }
}
