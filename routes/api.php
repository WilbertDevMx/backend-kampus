<?php

use App\Http\Controllers\Auth\AuthTokenController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\DiscoveryFilterController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileInterestController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SwipeController;
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

    // Funciones sociales: solo estudiantes. El staff no tiene perfil y solo usa el panel (RF-22).
    Route::middleware('role:student')->group(function () {
        // Perfil, intereses y fotos (T-07, RF-03).
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::get('/interests', [ProfileInterestController::class, 'index']);
        Route::put('/profile/interests', [ProfileInterestController::class, 'update']);
        Route::post('/profile/photos', [ProfilePhotoController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::delete('/profile/photos/{photo}', [ProfilePhotoController::class, 'destroy'])
            ->whereNumber('photo');

        // Descubrimiento, filtros y swipe (T-09, RF-05 y RF-06).
        Route::get('/discover', [DiscoveryController::class, 'index'])->name('discover.index');
        Route::get('/discover/filters', [DiscoveryFilterController::class, 'show'])->name('discover.filters.show');
        Route::put('/discover/filters', [DiscoveryFilterController::class, 'update'])->name('discover.filters.update');
        Route::post('/swipes', [SwipeController::class, 'store'])->name('swipes.store');
    });
});
