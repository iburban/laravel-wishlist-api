<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints.
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected endpoints (require a valid Sanctum token).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
});
