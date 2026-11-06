<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleCorrectionController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\WorkspacePosController;
use App\Http\Controllers\WorkspaceAdministrationController;
use App\Http\Controllers\WorkspaceInventoryController;
use App\Http\Controllers\WorkspacePartyController;
use App\Http\Controllers\WorkspacePurchaseController;
use App\Http\Middleware\RequireDevice;
use App\Http\Middleware\ResolveBranch;
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
    Route::prefix('workspace/pos')->middleware('can:sales.create')->group(function () {
        Route::view('/', 'workspace', ['section' => 'pos'])->name('workspace.pos');
        Route::middleware(ResolveBranch::class)->group(function () {
            Route::get('/products', [CatalogueController::class, 'index']);
            Route::get('/lookup', [CatalogueController::class, 'lookup']);
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
            Route::post('/customers', [CustomerController::class, 'save']);
            Route::post('/calculate', [WorkspacePosController::class, 'calculate']);
            Route::post('/checkout', [WorkspacePosController::class, 'checkout']);
            Route::get('/held', [SaleController::class, 'held']);
            Route::post('/held', [WorkspacePosController::class, 'hold']);
            Route::delete('/held/{sale}', [SaleController::class, 'discard'])->whereNumber('sale');
            Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store'])->whereNumber('sale');
            Route::post('/sales/{sale}/cancel', [SaleCorrectionController::class, 'cancel'])->whereNumber('sale');
            Route::patch('/sales/{sale}/comment', [SaleCorrectionController::class, 'comment'])->whereNumber('sale');
            Route::post('/credit-notes/{return}/cancel', [SaleCorrectionController::class, 'reverseReturn'])->whereNumber('return');
        });
    });
    Route::get('/workspace/pos/invoices/{branch}/{sale?}', [WorkspacePosController::class, 'invoices'])
        ->whereNumber(['branch', 'sale'])->name('workspace.pos.invoices');
    Route::get('/workspace/pos/invoices/{branch}/credit-note/{return}', [WorkspacePosController::class, 'creditNote'])
        ->whereNumber(['branch', 'return'])->name('workspace.pos.credit-note');
    Route::view('/workspace/stock', 'workspace', ['section' => 'stock'])->name('workspace.stock');
    Route::view('/workspace/reports', 'workspace', ['section' => 'reports'])->name('workspace.reports');
    Route::get('/workspace/report-data/{report}', [ReportController::class, 'show'])
        ->whereIn('report', ['dashboard', ...ReportController::TABLES])->middleware(ResolveBranch::class);
    Route::view('/workspace/customers', 'workspace', ['section' => 'customers'])->name('workspace.customers');
    Route::view('/workspace/suppliers', 'workspace', ['section' => 'suppliers'])->name('workspace.suppliers');
    Route::prefix('workspace/party-data')->group(function () {
        require __DIR__.'/parties.php';
        Route::get('/{kind}/{party}/entries/{source}/{entry}', [WorkspacePartyController::class, 'entry'])
            ->whereIn('kind', ['customers', 'suppliers'])->whereIn('source', ['opening', 'receipt', 'payment'])
            ->whereNumber(['party', 'entry'])->middleware(ResolveBranch::class);
    });
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
    Route::prefix('workspace/purchases')->name('workspace.purchases.')->group(function () {
        Route::get('/', [WorkspacePurchaseController::class, 'show'])->defaults('section', 'index')->name('index');
        Route::get('/new', [WorkspacePurchaseController::class, 'show'])->defaults('section', 'create')->name('create');
        Route::post('/', [WorkspacePurchaseController::class, 'create'])->name('store');
        Route::post('/supplier', [WorkspacePurchaseController::class, 'supplier'])->name('supplier');
        Route::get('/{purchase}', [WorkspacePurchaseController::class, 'show'])->defaults('section', 'detail')->whereNumber('purchase')->name('detail');
        Route::get('/{purchase}/correct', [WorkspacePurchaseController::class, 'show'])->defaults('section', 'correct')->whereNumber('purchase')->name('correct');
        Route::get('/{purchase}/history', [WorkspacePurchaseController::class, 'show'])->defaults('section', 'history')->whereNumber('purchase')->name('history');
        Route::post('/{purchase}/preview', [WorkspacePurchaseController::class, 'preview'])->defaults('operation', 'preview')->whereNumber('purchase')->name('preview');
        Route::post('/{purchase}/confirm', [WorkspacePurchaseController::class, 'confirm'])->whereNumber('purchase')->name('confirm');
    });
    Route::get('/auth/me', [AuthenticationController::class, 'me']);
    Route::post('/auth/logout', [AuthenticationController::class, 'logout']);
});
