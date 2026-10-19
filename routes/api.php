<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileInterestController;
use App\Http\Controllers\ProfilePhotoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::get('/interests', [ProfileInterestController::class, 'index']);
    Route::put('/profile/interests', [ProfileInterestController::class, 'update']);
    Route::post('/profile/photos', [ProfilePhotoController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::delete('/profile/photos/{photo}', [ProfilePhotoController::class, 'destroy'])
        ->whereNumber('photo');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
