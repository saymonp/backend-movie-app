<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genero extends Model
{
    use HasFactory;

    protected $table = 'generos';

    public $timestamps = false;
    // Por padrão o Laravel usa created_at e updated_at. 
    // desabilitar o updated_at automático.

    protected $fillable = ['nome'];

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_generos');
    }
}
