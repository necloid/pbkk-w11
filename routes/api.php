<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatAiController;
use App\Http\Controllers\Api\StatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute publik
|--------------------------------------------------------------------------
*/

Route::get('/status', StatusController::class)->name('api.status');

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('api.register');
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');
});

/*
|--------------------------------------------------------------------------
| Rute terproteksi token Sanctum
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me'])->name('api.user');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

    Route::post('/chat-ai', [ChatAiController::class, 'store'])
        ->middleware('throttle:chat-ai')
        ->name('api.chat-ai');

    Route::get('/chat-ai/history', [ChatAiController::class, 'index'])->name('api.chat-ai.history');
    Route::get('/chat-ai/history/{id}', [ChatAiController::class, 'show'])
        ->whereNumber('id')
        ->name('api.chat-ai.history.show');
});
