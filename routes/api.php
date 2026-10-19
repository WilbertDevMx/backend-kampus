<?php

use App\Http\Controllers\Auth\AuthTokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthTokenController::class, 'store'])->name('auth.login');

Route::post('/logout', [AuthTokenController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('auth.logout');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
