<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;
    use HasApiTokens, HasRoles;

    protected $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'slug'
    ];
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($user) {
            $user->slug = static::generateUniqueSlug($user->name);
        });
    }

    private static function generateUniqueSlug($name)
    {
        $slug = Str::slug($name);

        // Verifica se já existe alguém com esse slug na tabela de USERS
        $count = static::where('slug', 'like', "{$slug}%")->count();

        // Se já existir, concatena o número da contagem + 1 para ser único
        return $count ? "{$slug}-" . ($count + 1) : $slug;
    }

    // Relação: Um usuário tem muitas reviews
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function likedReviews()
    {
        return $this->belongsToMany(Review::class, 'review_like');
    }
}
