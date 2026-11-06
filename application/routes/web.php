<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\WorkspaceAdministrationController;
use App\Http\Middleware\RequireDevice;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthenticationController::class, 'signInPage'])->name('login');

Route::get('/auth/csrf', fn () => response()->json(['csrf_token' => csrf_token()]));
Route::post('/auth/login', [AuthenticationController::class, 'login'])
    ->middleware('throttle:login')->name('session.login');
Route::post('/sign-in', [AuthenticationController::class, 'login'])
    ->middleware('throttle:login')->name('session.sign-in');
Route::post('/sign-out', [AuthenticationController::class, 'logout'])
    ->middleware('auth:web')->name('session.sign-out');
Route::middleware(['auth:web', RequireDevice::class])->group(function () {
    Route::view('/workspace', 'workspace', ['section' => 'overview'])->name('workspace');
    Route::view('/workspace/stock', 'workspace', ['section' => 'stock'])->name('workspace.stock');
    Route::get('/workspace/admin/{section}', [WorkspaceAdministrationController::class, 'show'])
        ->whereIn('section', ['branches', 'staff', 'devices', 'settings', 'series'])->name('workspace.admin');
    Route::post('/workspace/admin/{section}/{record?}', [WorkspaceAdministrationController::class, 'save'])
        ->whereIn('section', ['branches', 'staff', 'devices', 'settings', 'series'])->whereNumber('record')->name('workspace.admin.save');
    Route::get('/auth/me', [AuthenticationController::class, 'me']);
    Route::post('/auth/logout', [AuthenticationController::class, 'logout']);
});
