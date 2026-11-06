<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SupplierController;
use App\Http\Middleware\ResolveBranch;
use Illuminate\Support\Facades\Route;

Route::get('suppliers', [SupplierController::class, 'index']);
Route::get('customers', [CustomerController::class, 'index']);
Route::post('customers', [CustomerController::class, 'save']);
Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
Route::patch('customers/{customer}', [CustomerController::class, 'save'])->whereNumber('customer');
Route::get('customers/{customer}/balances', [CustomerController::class, 'balances'])->whereNumber('customer');
Route::prefix('customers/{customer}')->where(['customer' => '[0-9]+'])->middleware(ResolveBranch::class)->group(function () {
    Route::get('ledger', [CustomerController::class, 'ledger']);
    Route::get('receipts/{entry}/allocations', [CustomerController::class, 'receiptAllocations'])->whereNumber('entry');
    foreach ([
        'opening-entries' => 'opening', 'opening-status/confirm-zero' => 'zero',
        'opening-entries/{entry}/reverse' => 'reverse-opening', 'receipts' => 'receipt', 'receipts/preview' => 'receipt-preview',
        'receipts/{entry}/apply' => 'receipt-apply', 'receipts/{entry}/reverse' => 'receipt-reverse',
        'receipts/{entry}/allocations/{allocation}/reverse' => 'receipt-unapply',
        'opening-entries/{entry}/apply-credit' => 'credit-apply',
        'opening-entries/{entry}/credit-allocations/{allocation}/reverse' => 'credit-unapply',
    ] as $path => $operation) {
        Route::post($path, [CustomerController::class, 'post'])->defaults('operation', $operation)->whereNumber(['entry', 'allocation']);
    }
});
Route::post('suppliers', [SupplierController::class, 'store']);
Route::match(['put', 'patch'], 'suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier');
Route::prefix('suppliers/{supplier}')->where(['supplier' => '[0-9]+'])->middleware(ResolveBranch::class)->group(function () {
    Route::get('ledger', [SupplierController::class, 'ledger']);
    foreach ([
        'opening-entries' => 'opening', 'opening-status/confirm-zero' => 'zero',
        'opening-entries/{entry}/reverse' => 'reverse-opening', 'payments' => 'payment', 'payments/preview' => 'payment-preview',
        'payments/{entry}/apply' => 'apply', 'payments/{entry}/reverse' => 'reverse-payment',
        'payments/{entry}/allocations/{allocation}/reverse' => 'unapply',
    ] as $path => $operation) {
        Route::post($path, [SupplierController::class, 'post'])->defaults('operation', $operation)->whereNumber(['entry', 'allocation']);
    }
});
