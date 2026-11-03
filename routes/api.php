<?php

use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\AuthTokenController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\DiscoveryFilterController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\Groups\GroupActivityController;
use App\Http\Controllers\Groups\GroupController;
use App\Http\Controllers\Groups\GroupImageController;
use App\Http\Controllers\Groups\GroupMembershipController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileInterestController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\RecoverPasswordController;
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

// Recuperación de contraseña por correo, con enlace de un solo uso (T-45).
Route::post('/forgot-password', [RecoverPasswordController::class, 'sendResetLink'])
    ->middleware('throttle:6,1')
    ->name('password.email');

Route::post('/reset-password', [RecoverPasswordController::class, 'reset'])
    ->middleware('throttle:6,1')
    ->name('password.update');

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

        // Matches (T-10, RF-07).
        Route::get('/matches', [MatchController::class, 'index'])->name('matches.index');

        // Grupos: explorar, crear, unirse y actividades (T-17, RF-11 y RF-12).
        Route::prefix('groups')
            ->name('groups.')
            ->where(['group' => '[0-9]+', 'activity' => '[0-9]+'])
            ->scopeBindings()
            ->group(function () {
                Route::get('/', [GroupController::class, 'index'])->name('index');
                Route::get('/mine', [GroupController::class, 'mine'])->name('mine');
                Route::post('/', [GroupController::class, 'store'])->middleware('throttle:10,1')->name('store');
                Route::get('/{group}', [GroupController::class, 'show'])->name('show');
                Route::patch('/{group}', [GroupController::class, 'update'])->name('update');
                Route::delete('/{group}', [GroupController::class, 'destroy'])->name('destroy');
                Route::post('/{group}/image', [GroupImageController::class, 'store'])
                    ->middleware('throttle:10,1')
                    ->name('image.store');

                Route::get('/{group}/members', [GroupMembershipController::class, 'index'])->name('members.index');
                Route::post('/{group}/members', [GroupMembershipController::class, 'store'])->name('members.store');
                Route::delete('/{group}/members/me', [GroupMembershipController::class, 'destroy'])->name('members.destroy');

                Route::get('/{group}/activities', [GroupActivityController::class, 'index'])->name('activities.index');
                Route::post('/{group}/activities', [GroupActivityController::class, 'store'])->name('activities.store');
                Route::patch('/{group}/activities/{activity}', [GroupActivityController::class, 'update'])->name('activities.update');
                Route::delete('/{group}/activities/{activity}', [GroupActivityController::class, 'destroy'])->name('activities.destroy');
            });
    });
});

// Panel de administración (módulo de Administración): token, correo verificado y una cuenta de staff.
Route::middleware(['auth:sanctum', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    // Gestión de staff: solo administradores (T-41, RF-22).
    Route::middleware('role:admin')->group(function () {
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('/staff/{staff}', [StaffController::class, 'update'])
            ->whereNumber('staff')
            ->name('staff.update');
    });
});
