<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lista;
use Illuminate\Support\Facades\Auth;
use App\Models\Movie;
use Illuminate\Support\Facades\DB;

class ListaController extends Controller
{
    public function show($id)
    {
        $userId = Auth::guard('sanctum')->id();

        $lista = Lista::with([
            'user',
            'tags',
            // Filtramos apenas as colunas desejadas do relacionamento movies
            'movies' => function ($query) {
                $query->select([
                    'movies.id',              // Necessário para o Eloquent
                    'movies.tmdb_id',
                    'movies.titulo_br',       // Nome
                    'movies.titulo_en',
                    'movies.titulo_original',
                    'movies.slug_pt',
                    'movies.slug_en',
                    'movies.poster_thumb_br', // Poster BR
                    'movies.poster_thumb_us', // Poster EN
                    'movies.rating'           // Rating
                ]);
            }
        ])
            ->withCount('likes')
            ->withExists(['likes as is_liked' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->findOrFail($id);

        return response()->json($lista);
    }

    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $dados = $request->validate([
                'titulo' => 'required|string|max:255', // Deixei required pois uma lista sem nome é difícil de achar
                'comentario' => 'nullable|string',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:30',
                'movies' => 'required|array', // A lista precisa de filmes
                'movies.*.id' => 'required|integer', // Pode ser nulo se for recém-importado
            ]);

            // 1. Pegar o ID do usuário logado
            /** @var \App\Models\User $userId */
            $userId = Auth::user()->id;
            $dados['user_id'] = $userId;

            // 2. Criar a lista (apenas campos fillable)
            $lista = Lista::create([
                'titulo' => $dados['titulo'],
                'comentario' => $dados['comentario'],
                'user_id' => $dados['user_id'],
            ]);

            // 3. Sincronizar as tags
            if ($request->filled('tags')) {
                $lista->syncTags($dados['tags']);
            }

            // 4. Sincronizar filmes com Ordem
            // Usamos o $index do foreach para definir a ordem
            foreach ($request->movies as $index => $movieData) {
                $movie = Movie::where('id', $movieData['id'])
                    ->first();

                if ($movie) {
                    // attach() adiciona na tabela pivot com os dados extras
                    $lista->movies()->attach($movie->id, [
                        'ordem' => $index
                    ]);
                }
            }
            // Retorna a lista completa com os 4 primeiros filmes (seguindo o padrão do index)
            return response()->json(
                $lista->load(['tags', 'user', 'movies' => function ($q) {
                    $q->orderBy('list_movie.ordem', 'asc');
                }]),
                201
            );
        });
    }


    public function index(Request $request)
    {
        // Tenta pegar o usuário sem exigir autenticação
        $user = Auth::guard('sanctum')->user();
        $userId = $user ? $user->id : null;

        $query = Lista::query()
            ->with([
                'tags:id,nome',
                'movies' => function ($q) {
                    $q->select('movies.id', 'poster_thumb_br', 'poster_thumb_us')
                        ->orderBy('list_movie.ordem', 'asc')
                        ->limit(4);
                }
            ])
            ->withCount('likes')
            ->when($userId, function ($q) use ($userId) {
                $q->withExists(['likes as is_liked' => function ($l) use ($userId) {
                    $l->where('user_id', $userId);
                }]);
            });

        // --- LÓGICA DE PRIVACIDADE E FILTRO DE USUÁRIO ---
        if ($request->boolean('user_only') && $userId) {
            $query->where('user_id', $userId);
        } else {
            $query->where(function ($q) use ($userId) {
                $q->where('publica', true);
                if ($userId) {
                    $q->orWhere('user_id', $userId);
                }
            });
        }

        // --- FILTROS DE BUSCA ---

        // Busca por texto
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('titulo', 'ILIKE', "%{$search}%")
                    ->orWhere('comentario', 'ILIKE', "%{$search}%")
                    ->orWhereHas('tags', function ($tagQuery) use ($search) {
                        $tagQuery->where('nome', 'ILIKE', "%{$search}%");
                    });
            });
        }

        // Filtro por Tags (IDs)
        if ($request->filled('tags')) {
            $tags = (array) $request->input('tags');
            $query->whereHas('tags', function ($q) use ($tags) {
                $q->whereIn('tags.id', $tags);
            });
        }

        // --- NOVO FILTRO: Mínimo de Likes ---
        // Ex: ?filterValue=10
        if ($request->filled('filterValue')) {
            $minLikes = (int) $request->input('filterValue');
            $query->has('likes', '>=', $minLikes);
        }

        // --- ORDENAÇÃO ---
        if ($request->boolean('popular')) {
            $query->orderBy('likes_count', 'desc');
        } else {
            $query->latest();
        }

        // --- EXECUÇÃO ÚNICA DA PAGINAÇÃO ---
        return response()->json(
            $query->paginate(12)->withQueryString()
        );
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $lista = Lista::findOrFail($id);

            // Segurança: Só o dono da lista ou um Admin pode editar
            if ($request->user()->id !== $lista->user_id && !$request->user()->hasRole('admin')) {
                return response()->json(['message' => 'Não autorizado'], 403);
            }

            $dados = $request->validate([
                'titulo' => 'nullable|string',
                'comentario' => 'nullable|string',
                'tags.*' => 'nullable|string|max:30',
                'movies' => 'nullable|array',
                'movies.*' => 'exists:movies,id'
            ]);

            $lista->update($dados);

            // Atualiza as tags na pivot
            if ($request->filled('tags')) {
                $lista->syncTags($dados['tags']);
            }

            // Atualiza os filmes na pivot
            if ($request->has('movies')) {
                $lista->movies()->sync($dados['movies']);

                $orderedIds = $dados['movies']; // Ex: [5, 2, 8, 1]

                // Atualizamos a ordem na tabela pivot
                foreach ($orderedIds as $index => $movieId) {
                    $lista->movies()->updateExistingPivot($movieId, [
                        'ordem' => $index // O índice do array vira a posição no banco
                    ]);
                }
            }

            return response()->json([
                'message' => 'Lista atualizada com sucesso',
                'data' => $lista->load(['tags', 'user', 'movies'])
            ]);
        });
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

    public function reorderMovies(Request $request, $listaId)
    {
        $request->validate([
            'movie_ids' => 'required|array',
            'movie_ids.*' => 'exists:movies,id'
        ]);

        $lista = Lista::findOrFail($listaId);

        // Segurança: Apenas o dono pode reordenar
        if ($request->user()->id !== $lista->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $orderedIds = $request->movie_ids; // Ex: [5, 2, 8, 1]

        // Atualizamos a ordem na tabela pivot
        foreach ($orderedIds as $index => $movieId) {
            $lista->movies()->updateExistingPivot($movieId, [
                'ordem' => $index // O índice do array vira a posição no banco
            ]);
        }

        return response()->json(['message' => 'Ordem atualizada!']);
    }

    public function toggleLike(Request $request, $listaId)
    {
        $user = $request->user();
        $lista = Lista::findOrFail($listaId);

        // O toggle gerencia a inserção ou remoção na tabela pivot
        $res = $lista->likes()->toggle($user->id);

        $liked = count($res['attached']) > 0;

        return response()->json([
            'message' => $liked ? 'Like adicionado' : 'Like removido',
            'is_liked' => $liked,
            'likes_count' => $lista->likes()->count()
        ]);
    }
}
