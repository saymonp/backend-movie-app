<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        // Criar as listas padrão de forma atômica
        $listasPadrao = [
            [
                'titulo' => 'Assistir Mais Tarde',
                'slug' => 'watch-later',
                'is_default' => true,
                'idioma' => 'pt',
                'publica' => false
            ],
            [
                'titulo' => 'Assistidos',
                'slug' => 'watched',
                'is_default' => true,
                'idioma' => 'pt',
                'publica' => false,
            ],
        ];

        foreach ($listasPadrao as $lista) {
            $user->listas()->create($lista);
        }
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        //
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        //
    }
}
