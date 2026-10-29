<?php

use App\Http\Controllers\Auth\AuthTokenController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\RegisterController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Registro con correo institucional y verificación por enlace firmado (T-04, RF-01).
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('auth.register');

Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware('signed')
    ->name('verification.verify');

Route::post('/login', [AuthTokenController::class, 'store'])->name('auth.login');

Route::post('/logout', [AuthTokenController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('auth.logout');

// Rutas de usuario: requieren token y correo verificado (RF-01).
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/user', fn (Request $request) => new UserResource($request->user()))->name('user.show');
});
