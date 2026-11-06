<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Middleware\RequireDevice;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('workspace') : view('welcome'))->name('login');

Route::get('/auth/csrf', fn () => response()->json(['csrf_token' => csrf_token()]));
Route::post('/auth/login', [AuthenticationController::class, 'login'])
    ->middleware('throttle:login')->name('session.login');
Route::middleware(['auth:web', RequireDevice::class])->group(function () {
    Route::view('/workspace', 'workspace', ['section' => 'overview'])->name('workspace');
    Route::view('/workspace/stock', 'workspace', ['section' => 'stock'])->name('workspace.stock');
    Route::get('/auth/me', [AuthenticationController::class, 'me']);
    Route::post('/auth/logout', [AuthenticationController::class, 'logout']);
});
