<?php

use App\Http\ApiResponse;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\BranchAdministrationController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CatalogueImportController;
use App\Http\Controllers\CatalogueReferenceController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DocumentSeriesController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseCorrectionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleCorrectionController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\ShopSettingsController;
use App\Http\Controllers\StaffAdministrationController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockController;
use App\Http\Middleware\RequireDevice;
use App\Http\Middleware\ResolveBranch;
use App\Services\SalePricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/token', [AuthenticationController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', RequireDevice::class])->group(function () {
        Route::get('auth/me', [AuthenticationController::class, 'me']);
        Route::post('auth/logout', [AuthenticationController::class, 'logout']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);
        Route::get('branches', [BranchAdministrationController::class, 'index']);
        Route::post('branches', [BranchAdministrationController::class, 'store']);
        Route::patch('branches/{branch}', [BranchAdministrationController::class, 'update'])->whereNumber('branch');
        Route::patch('branches/{branch}/active', [BranchAdministrationController::class, 'update'])->whereNumber('branch');
        Route::get('gst-states', [BranchAdministrationController::class, 'states']);
        Route::get('users', [StaffAdministrationController::class, 'index']);
        Route::post('users', [StaffAdministrationController::class, 'store']);
        Route::patch('users/{staff}', [StaffAdministrationController::class, 'update'])->whereNumber('staff');
        Route::patch('users/{staff}/active', [StaffAdministrationController::class, 'update'])->whereNumber('staff');
        Route::post('users/{staff}/reset-password', [StaffAdministrationController::class, 'update'])->whereNumber('staff');
        Route::get('settings', [ShopSettingsController::class, 'show']);
        Route::match(['post', 'put', 'patch'], 'settings', [ShopSettingsController::class, 'update']);
        Route::get('settings/logo', [ShopSettingsController::class, 'logo']);
        Route::middleware(ResolveBranch::class)->group(function () {
            Route::get('dashboard', [ReportController::class, 'show'])->defaults('report', 'dashboard');
            Route::get('reports/{report}', [ReportController::class, 'show'])->whereIn('report', [
                'daily-sales', 'sales-by-product', 'sales-by-cashier', 'stock-valuation', 'low-stock',
                'customer-outstanding', 'supplier-outstanding', 'payments', 'profit',
            ]);
            Route::get('document-series', [DocumentSeriesController::class, 'index']);
            Route::post('document-series', [DocumentSeriesController::class, 'store']);
            Route::post('sales', [SaleController::class, 'store']);
            Route::get('sales/held', [SaleController::class, 'held']);
            Route::delete('sales/{sale}', [SaleController::class, 'discard'])->whereNumber('sale');
            Route::post('sales/{sale}/cancel', [SaleCorrectionController::class, 'cancel'])->whereNumber('sale');
            Route::post('sale-returns/{return}/cancel', [SaleCorrectionController::class, 'reverseReturn'])->whereNumber('return');
            Route::patch('sales/{sale}/comment', [SaleCorrectionController::class, 'comment'])->whereNumber('sale');
            Route::get('sales/{sale}/comment-history', [SaleCorrectionController::class, 'commentHistory'])->whereNumber('sale');
            Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->whereNumber('sale');
            Route::get('sale-returns/{return}/invoice', [SaleReturnController::class, 'invoice'])->whereNumber('return');
            Route::get('sales/{sale}/invoice', [SaleController::class, 'invoice'])->whereNumber('sale');
            Route::get('sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale');
            Route::post('sales/calculate', fn (Request $request, SalePricing $pricing) => DB::transaction(
                fn () => ApiResponse::data($pricing->calculate($request->user(), $request->attributes->get('branch'), $request->all()))
            ));
            Route::get('purchases', [PurchaseController::class, 'index']);
            Route::post('purchases', [PurchaseController::class, 'store']);
            Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase');
            Route::post('purchases/{purchase}/revision-preview', [PurchaseCorrectionController::class, 'handle'])->defaults('operation', 'preview')->whereNumber('purchase');
            Route::match(['patch', 'delete'], 'purchases/{purchase}', [PurchaseCorrectionController::class, 'handle'])->whereNumber('purchase');
            Route::post('purchases/{purchase}/cancel', [PurchaseCorrectionController::class, 'handle'])->defaults('operation', 'cancel')->whereNumber('purchase');
            Route::get('purchases/{purchase}/revisions', [PurchaseCorrectionController::class, 'history'])->whereNumber('purchase');
        });
        require __DIR__.'/parties.php';
        Route::get('stock', [StockController::class, 'index'])->middleware(ResolveBranch::class);
        Route::get('stock/ledger', [StockController::class, 'ledger'])->middleware(ResolveBranch::class);
        Route::middleware(ResolveBranch::class)->prefix('stock')->group(function () {
            Route::get('adjustments', [StockAdjustmentController::class, 'index']);
            Route::post('adjustments', [StockAdjustmentController::class, 'adjust']);
            Route::post('count', [StockAdjustmentController::class, 'count']);
        });
        Route::get('products', [CatalogueController::class, 'index']);
        Route::get('products/lookup', [CatalogueController::class, 'lookup'])->middleware(ResolveBranch::class);
        Route::post('products', [CatalogueController::class, 'store']);
        Route::post('products/import', CatalogueImportController::class);
        Route::match(['put', 'patch'], 'products/{product}', [CatalogueController::class, 'update'])->whereNumber('product');
        Route::patch('products/{product}/active', [CatalogueController::class, 'update'])->whereNumber('product');
        Route::delete('products/{product}', [CatalogueController::class, 'destroy'])->whereNumber('product');
        Route::get('products/{product}/price-history', [CatalogueController::class, 'history'])->whereNumber('product');
        Route::get('{reference}', [CatalogueReferenceController::class, 'index'])->whereIn('reference', ['categories', 'units', 'tax-rates']);
        Route::post('{reference}', [CatalogueReferenceController::class, 'store'])->whereIn('reference', ['categories', 'units', 'tax-rates']);
        Route::match(['put', 'patch'], '{reference}/{id}', [CatalogueReferenceController::class, 'update'])
            ->whereIn('reference', ['categories', 'units', 'tax-rates'])->whereNumber('id');
        Route::get('branch', fn (Request $r) => ApiResponse::data($r->attributes->get('branch')))
            ->middleware(ResolveBranch::class);
    });
});
