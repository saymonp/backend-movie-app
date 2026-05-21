<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tag extends Model
{
    use HasFactory;

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

    public function listas(): BelongsToMany
    {
        return $this->belongsToMany(Review::class, 'list_tag', 'tag_id', 'list_id');
    }
}
