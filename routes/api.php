<?php

use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:6,1');

Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware('signed')
    ->name('verification.verify');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
