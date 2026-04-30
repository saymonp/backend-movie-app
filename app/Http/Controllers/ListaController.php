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
        return DB::transaction(function () use ($request) {
            $dados = $request->validate([
                'titulo' => 'required|string|max:255', // Deixei required pois uma lista sem nome é difícil de achar
                'comentario' => 'nullable|string',
                'tags' => 'nullable|array',
                'tags.*' => 'exists:tags,id',
                'movies' => 'required|array', // A lista precisa de filmes
                'movies.*.id' => 'nullable|integer', // Pode ser nulo se for recém-importado
                'movies.*.tmdb_id' => 'required|integer', // tmdb_id é nossa âncora de segurança
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
                $lista->tags()->sync($dados['tags']);
            }

            // 4. Sincronizar filmes com Ordem
            // Usamos o $index do foreach para definir a ordem
            foreach ($request->movies as $index => $movieData) {
                $movie = Movie::where('id', $movieData['id'] ?? null)
                    ->orWhere('tmdb_id', $movieData['tmdb_id'])
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
        /** @var \App\Models\User $currentUser */
        $userId = Auth::user()->id;

        $query = Lista::query()
            ->with([
                'user:id,name',
                'tags:id,nome_pt,nome_en',
                'movies' => function ($q) {
                    $q->select('movies.id', 'poster_thumb_br')
                        ->orderBy('list_movie.ordem', 'asc')
                        ->limit(4);
                }
            ])
            ->withCount('likes') // Isso cria o campo 'likes_count' automaticamente
            ->withExists(['likes as is_liked' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }]);

        // --- FILTROS ---

        // Busca por texto (Título/Comentário)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('titulo', 'ILIKE', "%{$search}%")
                    ->orWhere('comentario', 'ILIKE', "%{$search}%");
            });
        }

        // Filtro por Tags
        if ($request->filled('tags')) {
            $tags = (array) $request->input('tags');
            $query->whereHas('tags', function ($q) use ($tags) {
                $q->whereIn('tags.id', $tags);
            });
        }

        // --- ORDENAÇÃO ---

        // Se o front enviar ?popular=1, ordena pelos mais curtidos
        if ($request->boolean('popular')) {
            $query->orderBy('likes_count', 'desc');
        } else {
            // Ordenação padrão: mais recentes
            $query->latest();
        }

        $listas = $query->paginate(12)->withQueryString();

        return response()->json($listas);
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
        $lista = Lista::findOrFail($listaId);

        // Segurança: Apenas o dono pode reordenar
        if ($request->user()->id !== $lista->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $orderedIds = $request->input('movie_ids'); // Ex: [5, 2, 8, 1]

        // Atualizamos a ordem na tabela pivot
        foreach ($orderedIds as $index => $movieId) {
            $lista->movies()->updateExistingPivot($movieId, [
                'ordem' => $index // O índice do array vira a posição no banco
            ]);
        }

        return response()->json(['message' => 'Ordem atualizada!']);
    }
}
