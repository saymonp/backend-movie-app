<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'tmdb_id'           => 'required|integer|unique:movies,tmdb_id',
            'titulo_original'   => 'required|string',
            'titulo_br'         => 'nullable|string',
            'titulo_en'         => 'nullable|string',
            'descricao_br'      => 'nullable|string',
            'descricao_en'      => 'required|string',
            'tagline_br'        => 'nullable|string',
            'tagline_en'        => 'nullable|string',
            'slug_pt'           => 'nullable|string|unique:movies,slug_pt',
            'slug_en'           => 'required|string|unique:movies,slug_en',
            'rating'            => 'required|numeric',
            'duracao'           => 'required|integer',
            'lingua_origem'     => 'required|string|max:5',
            'release_date'      => 'required|date',
            'homepage'          => 'nullable|url',
            'poster_br'         => 'required|url',
            'poster_br_thumb'   => 'required|url',
            'backdrop_br'       => 'required|url',
            'poster_en'         => 'required|url',
            'poster_en_thumb'   => 'required|url',
            'generos'           => 'required|array',
            'diretor'           => 'required|array',
            'estudios'          => 'required|array',
            'paises'            => 'required|array',
            'colecao'           => 'nullable|array',
            'colecao.tmdb_id'   => 'required_with:colecao|integer',
            'colecao.name'      => 'required_with:colecao|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {

            // 1. Processamento Paralelo de Imagens (Reduz drasticamente o tempo de espera)
            $imageMapping = [
                'poster_br'       => 'poster_path_br',
                'poster_br_thumb' => 'poster_thumb_br',
                'backdrop_br'     => 'backdrop_path_br',
                'poster_en'       => 'poster_path_us',
                'poster_en_thumb' => 'poster_thumb_us',
            ];

            // Faz o download de todas as imagens simultaneamente
            $urls = collect($imageMapping)->map(fn($dbCol, $reqKey) => $request->input($reqKey))->toArray();
            $responses = Http::pool(fn($pool) => collect($urls)->map(fn($url) => $pool->get($url)));

            foreach ($imageMapping as $requestKey => $dbColumn) {
                $url = $request->input($requestKey);
                // Localiza a resposta correta no pool
                $res = collect($responses)->first(fn($r) => $r->effectiveUri() == $url);

                if ($res && $res->successful()) {
                    $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                    $path = "posters/" . Str::uuid() . "." . $extension;
                    Storage::disk('s3')->put($path, $res->body(), 'public');
                    $validated[$dbColumn] = $path;
                }
            }

            // 2. Criar o Filme
            $movie = Movie::create($validated);

            // 3. Processar Relacionamentos (Usando método auxiliar para evitar repetição)
            $this->syncRelations($movie, 'generos', \App\Models\Genero::class, $validated['generos']);
            $this->syncRelations($movie, 'diretores', \App\Models\Diretor::class, $validated['diretor']);
            $this->syncRelations($movie, 'estudios', \App\Models\Estudio::class, $validated['estudios']);
            $this->syncRelations($movie, 'paises', \App\Models\Pais::class, $validated['paises']);

            return response()->json(['id' => $movie->id], 201);
        });
    }

    /**
     * Método auxiliar para processar firstOrCreate e Sync de forma limpa
     */
    private function syncRelations($model, $relation, $relatedModel, $names)
    {
        if (empty($names)) return;

        // 1. Busca todos que já existem de uma vez só
        $existing = $relatedModel::whereIn('nome', $names)->get();

        // 2. Identifica o que precisa ser criado
        $existingNames = $existing->pluck('nome')->toArray();
        $newNames = array_diff($names, $existingNames);

        $newIds = [];
        foreach ($newNames as $name) {
            $newIds[] = $relatedModel::create(['nome' => $name])->id;
        }

        // 3. Junta os IDs existentes com os novos e sincroniza
        $allIds = $existing->pluck('id')->merge($newIds);
        $model->$relation()->sync($allIds);
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
    public function destroy($id)
    {
        $movie = Movie::findOrFail($id);
        $movie->delete();

        return response()->json(['message' => 'Filme removido com sucesso'], 204);
    }
}
