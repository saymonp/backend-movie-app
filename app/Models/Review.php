<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = ['titulo', 'comentario', 'rating', 'user_id', 'movie_id'];

    protected $casts = [
        'rating' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->as('tag'); // opcional
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function likes()
    {
        return $this->belongsToMany(
            User::class,
            'review_like',
            'review_id',
            'user_id'
        );
    }

    // Método para contar likes
    public function getLikesCountAttribute()
    {
        return $this->likes()->count();
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
