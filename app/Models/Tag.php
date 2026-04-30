<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = [
        'nome',
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];

    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class);
    }
}
