<?php

use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Socialite;
use App\Http\Controllers\LoginController;

Route::get('/', function () {
    return view('welcome');
});

// Rota 1: Redireciona o usuário para o Google
Route::get('/auth/google/redirect', function () {
    return Socialite::driver('google')->redirect();
});

// Rota 2: Onde o Google entrega os dados
Route::get('/auth/google/callback', [LoginController::class, 'handleGoogleCallback']);