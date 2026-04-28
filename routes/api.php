<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Socialite;

use App\Http\Controllers\MovieController;
use App\Http\Controllers\LoginController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rota 1: Redireciona o usuário para o Google
Route::get('/auth/google/redirect', function () {
    return Socialite::driver('google')->redirect();
});
// Rota 2: Onde o Google entrega os dados
Route::get('/auth/google/callback', [LoginController::class, 'handleGoogleCallback']);

// Rotas que exigem o seu token
Route::middleware('auth:sanctum')->group(function () {
    
    // O usuário só consegue postar uma review se tiver o SEU token
    //Route::post('/reviews', [ReviewController::class, 'store']);
    
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});


Route::apiResource('movies', MovieController::class);