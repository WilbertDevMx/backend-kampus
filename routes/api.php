<?php

use App\Http\Controllers\Auth\AuthTokenController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileInterestController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthTokenController::class, 'store'])->name('auth.login');

Route::post('/logout', [AuthTokenController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('auth.logout');

// Rutas de usuario: requieren token y correo verificado (RF-01).
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/user', fn (Request $request) => new UserResource($request->user()))->name('user.show');

    // Perfil, intereses y fotos (T-07, RF-03).
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::get('/interests', [ProfileInterestController::class, 'index']);
    Route::put('/profile/interests', [ProfileInterestController::class, 'update']);
    Route::post('/profile/photos', [ProfilePhotoController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::delete('/profile/photos/{photo}', [ProfilePhotoController::class, 'destroy'])
        ->whereNumber('photo');
});
