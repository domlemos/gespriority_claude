<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

// Staff (guard "web") e Cliente (guard "customer") têm login e recuperação de
// senha em rotas próprias, pois cada guard resolve contra seu próprio model/tabela.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:password-recovery');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:password-recovery');

Route::prefix('customer')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->defaults('guard', 'customer')
        ->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->defaults('guard', 'customer')
        ->middleware('throttle:password-recovery');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->defaults('guard', 'customer')
        ->middleware('throttle:password-recovery');
});

// Sessão autenticada: funciona para qualquer um dos dois guards, resolvido
// pelo token Bearer apresentado (o primeiro guard que autenticar vence).
Route::middleware('auth:web,customer')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
});

// Cliente (empresa contratante) — só staff (guard "web") com a permissão
// dedicada gerencia; Customer nunca acessa (ver BACKEND_SPECS.md seção 3.1).
Route::middleware(['auth:web', 'can:clients.manage'])->apiResource('clients', ClientController::class);

Route::middleware(['auth:web', 'can:users.manage'])->get('/roles', [RoleController::class, 'index']);
