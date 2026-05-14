<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Movie extends Model
{
    use HasFactory;

    // Por padrão o Laravel usa created_at e updated_at. 
    // Como seu SQL só tem created_at, vamos desabilitar o updated_at automático.
    const UPDATED_AT = null;

    protected $fillable = [
        'tmdb_id',
        'imdb_id',
        'titulo_original',
        'titulo_br',
        'descricao_br',
        'tagline_br',
        'titulo_en',
        'descricao_en',
        'tagline_en',
        'rating',
        'duracao',
        'lingua_origem',
        'poster_path_br',
        'poster_thumb_br',
        'backdrop_path',
        'poster_path_us',
        'poster_thumb_us',
        'backdrop_path_us',
        'homepage',
        'colecao_id',
        'slug_pt',
        'slug_en',
        'release_date',
        'revenue',
        'popularity'
    ];

    // Casts ajudam o Laravel a converter tipos do banco para o PHP
    protected $casts = [
        'rating' => 'float',
        'duracao' => 'integer',
        'release_date' => 'date',
        'created_at' => 'datetime',
        'revenue' => 'integer',
        'popularity' => 'float'
    ];

    /**
     * RELACIONAMENTOS
     */

    // Um filme pertence a uma coleção (Ex: Marvel Cinematic Universe)
    public function colecao(): BelongsTo
    {
        return $this->belongsTo(Colecao::class, 'colecao_id');
    }

    // Muitos para Muitos com Gêneros
    public function generos(): BelongsToMany
    {
        return $this->belongsToMany(Genero::class, 'movie_generos');
    }

    // Muitos para Muitos com Estúdios
    public function estudios(): BelongsToMany
    {
        return $this->belongsToMany(Estudio::class, 'movie_estudios');
    }

    // Muitos para Muitos com Diretores
    public function diretores(): BelongsToMany
    {
        return $this->belongsToMany(Diretor::class, 'movie_diretores');
    }

    // Muitos para Muitos com Países
    public function paises(): BelongsToMany
    {
        return $this->belongsToMany(Pais::class, 'movie_paises');
    }

    // Ao criar sincroniza Relações
    public function syncRelations($relation, $modelClass, $names)
    {
        if (empty($names)) return;

        $ids = collect($names)->map(function ($name) use ($modelClass) {
            $record = $modelClass::firstOrCreate(['nome' => trim($name)]);
            return $record->id;
        });

        $this->$relation()->sync($ids);
    }

    public function syncRelationsGeneros($relation, $relatedModel, $items)
    {
        if (empty($items)) return;

        $ids = collect($items)->map(function ($item) use ($relatedModel) {
            $registro = $relatedModel::updateOrCreate(
                ['tmdb_id' => $item['tmdb_id']],
                [
                    'nome_pt' => $item['nome_pt'],
                    'nome_en' => $item['nome_en'],
                ]
            );
            return $registro->id;
        })->all();

        $this->$relation()->sync($ids);
    }

    public function syncRelationsColecao($colecao)
    {
        if (empty($colecao)) return;

        // O updateOrCreate precisa de uma chave para comparar e os dados para atualizar
        // Exemplo: usar o tmdb_id da coleção para não duplicar no banco
        $registro = Colecao::updateOrCreate(
            ['tmdb_id' => $colecao['tmdb_id']],
            $colecao
        );

        // Para relacionamentos BelongsTo, usamos associate
        $this->colecao()->associate($registro);
        $this->save(); // Importante: associate() apenas altera o ID no objeto, save() grava no banco.
    }
}
