<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Socialite;

use App\Http\Controllers\MovieController;
use App\Http\Controllers\LoginController;

use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ListaController;

Route::middleware(['auth:sanctum'])->group(function () {
    // Grupo de operações de Lista
    Route::prefix('listas')->group(function () {
        // /api/listas/{id}/like
        Route::post('{id}/like', [ListaController::class, 'toggleLike']);
        /**
         * Alterar a ordem da lista
         * POST /api/listas/{id}
         */
        Route::post('/{id}', [ListaController::class, 'reorderMovies']);
        /**
         * Criar uma nova lista
         * POST /api/listas
         */
        Route::post('', [ListaController::class, 'store']);
        /**
         * Atualizar uma lista (Dono ou Admin)
         * PUT ou PATCH /api/listas/{id}
         */
        Route::put('/{id}', [ListaController::class, 'update']);
        /**
         * Deletar uma lista (Dono ou Admin)
         * DELETE /api/lista/{id}
         */
        Route::delete('/{id}', [ListaController::class, 'destroy']);
    });

    // Grupo de Reviews dentro do contexto de Filmes
    Route::prefix('movies/{movie_id}')->group(function () {
        /**
         * Listar reviews de um filme específico
         * GET /api/movies/{movie_id}/reviews
         */
        Route::get('/reviews', [ReviewController::class, 'index']);

        /**
         * Criar uma nova review para um filme
         * POST /api/movies/{movie_id}/reviews
         */
        Route::post('/reviews', [ReviewController::class, 'store']);
    });

    // Grupo de operações diretas em uma Review
    Route::prefix('reviews')->group(function () {
        Route::post('/reviews/{id}/like', [ReviewController::class, 'toggleLike']);
        /**
         * Atualizar uma review (Dono ou Admin)
         * PUT ou PATCH /api/reviews/{id}
         */
        Route::put('/{id}', [ReviewController::class, 'update']);

        /**
         * Deletar uma review (Dono ou Admin)
         * DELETE /api/reviews/{id}
         */
        Route::delete('/{id}', [ReviewController::class, 'destroy']);
    });

    Route::delete('/me/delete', [LoginController::class, 'deleteOwnAccount']);
    // Rotas exclusivas para Admins
    // O middleware 'role:admin' é fornecido pelo pacote Spatie
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        Route::post('/users/{id}/roles', [UserRoleController::class, 'assignRole']);
        Route::get('/roles', [UserRoleController::class, 'listRoles']);
        Route::get('/users', [UserRoleController::class, 'listUsers']);
        Route::delete('/users/{id}', [LoginController::class, 'destroy']);

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

/**
 * Ver detalhes de uma única review
 * GET /api/reviews/{id}
 */
Route::get('/reviews/{id}', [ReviewController::class, 'show']);

/**
 * Ver detalhes de uma lista
 * GET /api/listas/{id}
 */
Route::get('/listas/{id}', [ListaController::class, 'show']);
/**
 * Index Listas
 * GET /api/listas
 */
Route::get('/listas', [ListaController::class, 'index']);

/**
 * Index Filmes
 * GET /api/movies
 */
Route::get('/movies', [MovieController::class, 'index']);
/**
 * Show Filme
 * GET /api/movies
 */
Route::get('/movies/{id}', [MovieController::class, 'show']);

Route::post('/register', [LoginController::class, 'register']);
Route::post('/login', [LoginController::class, 'login']);
