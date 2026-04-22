<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Colecao extends Model
{
    use HasFactory;

    protected $table = 'colecoes';

    public $timestamps = false;
    // Por padrão o Laravel usa created_at e updated_at. 
    // desabilitar o updated_at automático.

    protected $fillable = [
        'tmdb_id',
        'nome',
        'poster_path',
        'poster_thumb',
        'backdrop_path'
    ];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * RELACIONAMENTOS
     */
    // Uma coleção tem vários filmes (Ex: Zootopia 1 e Zootopia 2 pertencem à mesma coleção)
    public function movies(): HasMany
    {
        return $this->hasMany(Movie::class, 'colecao_id');
    }
}
