<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->as('tag'); // opcional
    }

    public function movie(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class);
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
}
