<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\WorkspaceAdministrationController;
use App\Http\Controllers\WorkspaceInventoryController;
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
    Route::get('/workspace/inventory/{section}/{product?}', [WorkspaceInventoryController::class, 'show'])
        ->whereIn('section', ['products', 'edit', 'import', 'ledger', 'low-stock', 'movement', 'references'])->whereNumber('product')->name('workspace.inventory');
    Route::post('/workspace/inventory/product/{product?}', [WorkspaceInventoryController::class, 'saveProduct'])->whereNumber('product')->name('workspace.inventory.save');
    Route::post('/workspace/inventory/references/{reference}', [WorkspaceInventoryController::class, 'saveReference'])
        ->whereIn('reference', ['units', 'tax-rates', 'categories'])->name('workspace.inventory.reference');
    Route::post('/workspace/inventory/import', [WorkspaceInventoryController::class, 'import'])->name('workspace.inventory.import');
    Route::post('/workspace/inventory/movement/preview', [WorkspaceInventoryController::class, 'preview'])->name('workspace.inventory.preview');
    Route::post('/workspace/inventory/movement/post', [WorkspaceInventoryController::class, 'postMovement'])->name('workspace.inventory.post');
    Route::get('/auth/me', [AuthenticationController::class, 'me']);
    Route::post('/auth/logout', [AuthenticationController::class, 'logout']);
});
