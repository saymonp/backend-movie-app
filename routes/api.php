<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Socialite;

use App\Http\Controllers\MovieController;
use App\Http\Controllers\LoginController;

use App\Http\Controllers\UserRoleController;

Route::middleware(['auth:sanctum'])->group(function () {

    // Rotas exclusivas para Admins
    // O middleware 'role:admin' é fornecido pelo pacote Spatie
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        Route::post('/users/{id}/roles', [UserRoleController::class, 'assignRole']);
        Route::get('/roles', [UserRoleController::class, 'listRoles']);
        Route::get('/users', [UserRoleController::class, 'listUsers']);

        // Importar único Filme pelo TMDb_id
        Route::post('/movies/single/{tmdb_id}', [MovieController::class, 'store']);

        // Importar filmes em Lote
        Route::post('/movies/batch/{limit}', [MovieController::class, 'importMovies']);

        // Atualizar Filme TODO: imagens tipo File
        Route::put('/movies/update/{id}', [MovieController::class, 'update']);

        // Excluir Filme
        Route::delete('/movies/delete/{id}', [MovieController::class, 'destroy']);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    // Aqui você pode carregar as roles e permissões também
    return $request->user()->load('roles');
});

// Rotas que exigem o seu token
Route::middleware('auth:sanctum')->group(function () {

    // O usuário só consegue postar uma review se tiver o SEU token
    //Route::post('/reviews', [ReviewController::class, 'store']);

    Route::get('/user', function (Request $request) {
        return $request->user()->load('roles');
    });
});

Route::post('/register', [LoginController::class, 'register']);
Route::post('/login', [LoginController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/users/{id}', [LoginController::class, 'destroy']);
});

//Route::apiResource('movies', MovieController::class);
