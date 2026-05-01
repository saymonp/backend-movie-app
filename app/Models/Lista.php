<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tag;

class Lista extends Model
{
    protected $fillable = ['titulo', 'comentario', 'user_id'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];
    protected $table = 'lists';
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'list_tag', 'list_id', 'tag_id');
    }


    public function likes()
    {
        return $this->belongsToMany(User::class, 'like_list');
    }

    // Método para contar likes
    public function getLikesCountAttribute()
    {
        return $this->likes()->count();
    }

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'list_movie', 'list_id', 'movie_id')
            ->withPivot('ordem') // Permite acessar $movie->pivot->ordem
            ->orderBy('list_movie.ordem', 'asc'); // Garante que venha ordenado por padrão
    }

    // Ao criar sincroniza Tags
    public function syncTags($tags)
    {
        if (empty($tags)) return;

        $ids = collect($tags)->map(function ($nome) {
            $record = Tag::firstOrCreate(['nome' => trim($nome)]);
            return $record->id;
        });

        $this->tags()->sync($ids);
    }
}
