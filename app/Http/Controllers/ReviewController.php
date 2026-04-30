<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Review;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    /**
     * Lista as reviews de um filme específico.
     */
    public function index($movie_id)
    {
        // Retorna as reviews do filme com os dados do autor (user) e as tags
        $reviews = Review::where('movie_id', $movie_id)
            ->with(['user', 'tags'])
            ->latest()
            ->paginate(10);

        return response()->json($reviews);
    }

    /**
     * Exibe uma review detalhada.
     */
    public function show($id)
    {
        $review = Review::with(['user', 'tags', 'movie'])->findOrFail($id);
        return response()->json($review);
    }

    /**
     * Atualiza uma review existente.
     */
    public function update(Request $request, $id)
    {
        $review = Review::findOrFail($id);

        // Segurança: Só o dono da review ou um Admin pode editar
        if ($request->user()->id !== $review->user_id && !$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $dados = $request->validate([
            'titulo' => 'nullable|string',
            'comentario' => 'nullable|string',
            'rating' => 'required|numeric|min:0|max:5',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id'
        ]);

        $review->update($dados);

        // Atualiza as tags na pivot
        if ($request->has('tags')) {
            $review->tags()->sync($dados['tags']);
        }

        return response()->json([
            'message' => 'Review atualizada com sucesso',
            'data' => $review->load('tags')
        ]);
    }

    public function store(Request $request, $movie_id)
    {
        $dados = $request->validate([
            'titulo' => 'nullable|string',
            'comentario' => 'nullable|string',
            'rating' => 'required|numeric|min:0|max:5',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id'
        ]);

        // 1. Pegar o ID do usuário logado pelo Token
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::user();
        $dados['user_id'] = $currentUser->id;

        // 2. Pegar o ID do filme que veio da URL
        $dados['movie_id'] = $movie_id;

        // 3. Criar a review
        $review = Review::create($dados);

        // 4. Sincronizar as tags na tabela pivot
        if ($request->has('tags')) {
            $review->tags()->sync($dados['tags']);
        }

        return response()->json($review->load(['tags', 'user', 'movie']), 201);
    }

    /**
     * Remove uma review.
     */
    public function destroy(Request $request, $id)
    {
        $review = Review::findOrFail($id);

        // Segurança: Só o dono ou Admin pode deletar
        if ($request->user()->id !== $review->user_id && !$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $review->delete();

        return response()->json(['message' => 'Review removida'], 200);
    }
}
