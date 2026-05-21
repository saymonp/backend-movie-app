<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Review;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * Lista as reviews de um filme específico.
     */
    public function index(Request $request, $movie_id = null)
    {
        // 1. Identifica o usuário (Sanctum ou Auth padrão)
        $userId = Auth::guard('sanctum')->id() ?? Auth::id();

        $query = Review::query()
            ->with([
                'user:id,name,avatar',
                'tags:id,nome',
                'movie:id,titulo_br,titulo_original,titulo_en,poster_thumb_br,poster_thumb_us'
            ])
            ->withCount('likes')
            ->withExists(['likes as is_liked' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            // só filtra por movie_id se ele for passado
            ->when($movie_id, function ($q) use ($movie_id) {
                $q->where('movie_id', $movie_id);
            });

        // 2. Lógica de Privacidade e Filtro de Usuário (user_only)
        if ($request->boolean('user_only') && $userId) {
            $query->where('user_id', $userId);
        }

        // 3. Ordenação e Paginação
        $reviews = $query->latest()->paginate(10);

        return response()->json($reviews);
    }

    /**
     * Exibe uma review detalhada.
     */
    public function show($id)
    {
        $userId = Auth::guard('sanctum')->id();
        $review = Review::with([
            'user:id,name,avatar',
            'tags:id,nome',
            'movie:id,titulo_br,titulo_original,titulo_en,poster_thumb_br,poster_thumb_us'
        ])->withCount('likes')
            ->withExists(['likes as is_liked' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->findOrFail($id);

        return response()->json($review);
    }

    public function showMovieReview(int $movieId)
    {
        $userId = Auth::id();

        // Busca a review que pertence ao usuário e ao filme específico
        $review = Review::with(['tags:id,nome'])
            ->withCount('likes')
            ->where('user_id', $userId)
            ->where('movie_id', $movieId)
            ->first(); // Usamos first() para retornar null se não existir, ou firstOrFail() para 404

        if (!$review) {
            return response()->json(['message' => 'Review não encontrada'], 404);
        }

        return response()->json($review);
    }

    /**
     * Atualiza uma review existente.
     */
    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $review = Review::findOrFail($id);

            // Segurança: Só o dono da review ou um Admin pode editar
            if ($request->user()->id !== $review->user_id && !$request->user()->hasRole('admin')) {
                return response()->json(['message' => 'Não autorizado'], 403);
            }

            $dados = $request->validate([
                'titulo' => 'nullable|string',
                'comentario' => 'nullable|string',
                'rating' => 'required|numeric|min:0|max:5',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:30',
            ]);

            $review->update($dados);

            // Sincronizar as tags
            if ($request->filled('tags')) {
                $review->syncTags($dados['tags']);
            }

            return response()->json([
                'message' => 'Review atualizada com sucesso',
                'data' => $review->load('tags')
            ]);
        });
    }

    public function store(Request $request, $movie_id)
    {
        return DB::transaction(function () use ($request, $movie_id) {
            $dados = $request->validate([
                'titulo' => 'nullable|string',
                'comentario' => 'nullable|string',
                'rating' => 'required|numeric|min:0|max:5',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:30',
            ]);

            $userId = Auth::id();

            // 1. Busca por user_id e movie_id, se achar atualiza os 'dados', se não cria.
            $review = Review::updateOrCreate(
                [
                    'user_id' => $userId,
                    'movie_id' => $movie_id
                ],
                [
                    'titulo' => $dados['titulo'],
                    'comentario' => $dados['comentario'],
                    'rating' => $dados['rating'],
                ]
            );

            // 2. Sincronizar as tags (funciona tanto para novo quanto para editado)
            if ($request->has('tags')) {
                // Se as tags vierem vazias [], ele remove as antigas. Se não vierem no request, mantém as que estão.
                $review->syncTags($dados['tags'] ?? []);
            }

            // Retornamos 200 (OK) em vez de 201 (Created) pois pode ser uma atualização
            return response()->json($review->load(['tags', 'user', 'movie']), 200);
        });
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

    public function toggleLike(Request $request, $reviewId)
    {
        $user = $request->user();
        $review = Review::findOrFail($reviewId);

        // O toggle gerencia a inserção ou remoção na tabela pivot
        //$res = $user->likedReviews()->toggle($review->id);
        $res = $review->likes()->toggle($user->id);

        $liked = count($res['attached']) > 0;

        return response()->json([
            'message' => $liked ? 'Like adicionado' : 'Like removido',
            'is_liked' => $liked,
            'likes_count' => $review->likes()->count()
        ]);
    }
}
