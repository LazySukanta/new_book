<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\CatalogueWriter;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\PurchaseRevisionHistory;
use App\Services\PurchaseTotals;
use App\Services\ShopCalendar;
use App\Services\StockPosting;
use App\Services\SupplierAccountPosting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('purchases.manage'), 403);
        $branch = $request->attributes->get('branch');
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100', 'status' => 'sometimes|in:posted,deleted']);
        if (($filters['status'] ?? 'posted') === 'deleted') {
            abort_unless($request->user()->hasRole('Owner'), 403);
        }
        $page = DB::table('purchases')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'status' => $filters['status'] ?? 'posted'])
            ->orderByDesc('received_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);

        return ApiResponse::data($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        abort_unless($request->user()->can('purchases.manage'), 403);
        $branch = $request->attributes->get('branch');
        $record = DB::table('purchases')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'id' => $purchase])->first();
        abort_unless($record, 404);
        abort_if($record->status === 'deleted' && ! $request->user()->hasRole('Owner'), 403);

        return ApiResponse::data((array) $record + [
            'returns' => DB::table('purchase_returns')->where('purchase_id', $purchase)->orderByDesc('id')->get(),
            'items' => DB::table('purchase_items')->where('purchase_id', $purchase)->where('is_removed', false)
                ->select('purchase_items.*')->selectRaw('qty - COALESCE((SELECT SUM(returned.qty) FROM purchase_return_items AS returned WHERE returned.purchase_item_id = purchase_items.id), 0) AS returnable_qty')
                ->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('purchases.manage'), 403);
        $data = $request->validate([
            'supplier_id' => 'required|integer|min:1',
            'supplier_invoice_no' => 'required|string|max:120',
            'supplier_invoice_date' => 'required|date_format:Y-m-d',
            'cost_includes_tax' => 'required|boolean',
            'tax_type' => 'required|in:intra,inter',
            'input_tax_recoverable' => 'required|boolean',
            'discount_amount' => 'sometimes|required|string',
            'received_at' => 'prohibited',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'array:product_id,qty,unit_cost,rate_bp',
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'items.*.qty' => 'required|string',
            'items.*.unit_cost' => 'required|string',
            'items.*.rate_bp' => 'sometimes|required|integer|between:0,10000',
            'payments' => 'sometimes|array|max:10',
            'payments.*' => 'array:mode,amount,reference',
            'payments.*.mode' => 'required|in:cash,upi,card,bank',
            'payments.*.amount' => 'required|string',
            'payments.*.reference' => 'sometimes|nullable|string|max:255',
        ]);
        if (($data['payments'] ?? []) !== []) {
            abort_unless($request->user()->can('payments.pay_supplier'), 403);
        }

        return $transaction->run($request, 'purchase.post', $data, fn (int $requestId) => $this->post($request, $data, $requestId));
    }

    private function post(Request $request, array $data, int $requestId): JsonResponse
    {
        $user = $request->user();
        $branch = $request->attributes->get('branch');
        $now = now();
        if ($data['supplier_invoice_date'] > ShopCalendar::localDate($now)) {
            throw ValidationException::withMessages(['supplier_invoice_date' => 'The supplier invoice date cannot be in the future.']);
        }
        $accounts = app(SupplierAccountPosting::class);
        $account = $accounts->account($request, (int) $data['supplier_id']);
        $supplier = Supplier::where('tenant_id', $user->tenant_id)->findOrFail($account->supplier_id);
        abort_unless($supplier->is_active, 422, 'Activate the supplier before posting a new purchase.');
        $normalized = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $data['supplier_invoice_no'])));
        if ($normalized === '' || mb_strlen($normalized) > 120) {
            throw ValidationException::withMessages(['supplier_invoice_no' => 'Enter a valid supplier invoice number.']);
        }
        if (DB::table('purchases')->where(['supplier_id' => $supplier->id, 'normalized_invoice_no' => $normalized])->exists()) {
            return ApiResponse::conflict('DUPLICATE_SUPPLIER_INVOICE', 'This supplier invoice number already exists.');
        }
        $products = Product::where('tenant_id', $user->tenant_id)->where('is_active', true)
            ->whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($data['items'])) {
            throw ValidationException::withMessages(['items' => 'Select distinct, active products from this shop.']);
        }
        $units = DB::table('units')->whereIn('id', $products->pluck('unit_id'))->pluck('allow_decimal', 'id');
        $rates = DB::table('tax_rates')->whereIn('id', $products->pluck('tax_rate_id'))->pluck('rate_bp', 'id');
        $inputs = [];
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $inputs[] = [
                'product_id' => $product->id, 'qty' => Money::quantity($item['qty'], (bool) $units[$product->unit_id]),
                'unit_cost' => Money::amount($item['unit_cost']), 'rate_bp' => (int) ($item['rate_bp'] ?? $rates[$product->tax_rate_id]),
            ];
        }
        $discount = Money::amount($data['discount_amount'] ?? '0.00');
        $inclusive = (bool) $data['cost_includes_tax'];
        $recoverable = (bool) $data['input_tax_recoverable'];
        $calculated = app(PurchaseTotals::class)->calculate($inputs, $discount, $inclusive, $data['tax_type'], $recoverable);
        $totals = $calculated['totals'];
        $payments = $data['payments'] ?? [];
        $paid = BigDecimal::zero()->toScale(2);
        foreach ($payments as $payment) {
            $paid = $paid->plus(Money::amount($payment['amount']));
        }
        if ($paid->isGreaterThan($totals['total'])) {
            throw ValidationException::withMessages(['payments' => 'Payment exceeds the purchase total.']);
        }
        $header = $totals + [
            'tenant_id' => $user->tenant_id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'device_id' => $request->attributes->get('device')?->id, 'created_by' => $user->id,
            'supplier_invoice_no' => $data['supplier_invoice_no'], 'normalized_invoice_no' => $normalized,
            'supplier_invoice_date' => $data['supplier_invoice_date'], 'received_at' => $now,
            'cost_includes_tax' => $inclusive, 'input_tax_recoverable' => $recoverable, 'tax_type' => $data['tax_type'],
            'discount_amount' => $discount, 'unrounded_total' => $totals['total'], 'round_off' => '0.00',
            'paid_amount' => (string) $paid, 'balance_amount' => (string) BigDecimal::of($totals['total'])->minus($paid),
            'status' => 'posted', 'current_revision' => 1,
            'supplier_snapshot' => $supplier->only('id', 'name', 'address', 'phone', 'gstin', 'state_code'),
            'branch_snapshot' => $branch->only('id', 'code', 'name', 'address', 'gstin', 'state_code'),
        ];
        $id = DB::table('purchases')->insertGetId(array_replace($header, [
            'supplier_snapshot' => json_encode($header['supplier_snapshot'], JSON_THROW_ON_ERROR),
            'branch_snapshot' => json_encode($header['branch_snapshot'], JSON_THROW_ON_ERROR),
        ]));
        $lines = [];
        foreach ($calculated['lines'] as $line) {
            $product = $products[$line['product_id']];
            $line += ['purchase_id' => $id, 'price_includes_tax' => $inclusive, 'product_snapshot' => $product->only('id', 'sku', 'name', 'hsn', 'unit_id')];
            $itemId = DB::table('purchase_items')->insertGetId(array_replace($line, [
                'product_snapshot' => json_encode($line['product_snapshot'], JSON_THROW_ON_ERROR),
            ]));
            $lines[$product->id] = $line + ['id' => $itemId];
        }
        ksort($lines, SORT_NUMERIC);
        foreach ($lines as $productId => $line) {
            app(StockPosting::class)->receive($user, $branch->id, $productId, $line['id'], $line['qty'], $line['inventory_unit_cost'], $line['inventory_value']);
            $product = $products[$productId];
            $before = app(CatalogueWriter::class)->pricing($product);
            $next = (string) BigDecimal::of($line['inventory_unit_cost'])->toScale(2, RoundingMode::HalfUp);
            if ($product->purchase_price !== $next) {
                $product->forceFill(['purchase_price' => $next])->save();
                app(PostingAudit::class)->record($request, 'product.pricing', 'product', $productId, $before,
                    app(CatalogueWriter::class)->pricing($product), 'Supplier purchase cost');
            }
        }
        $allocations = [];
        foreach ($payments as $payment) {
            $record = $accounts->payment($request, $account, $payment + ['auto_allocate' => false]);
            $allocations[] = DB::table('payment_allocations')->insertGetId([
                'payment_id' => $record->id, 'purchase_id' => $id, 'amount' => $record->amount,
                'effective_at' => $record->paid_at, 'created_at' => $now, 'created_by' => $user->id,
            ]);
        }
        $snapshot = $header + ['id' => $id, 'items' => array_values($lines), 'allocation_ids' => $allocations];
        app(PurchaseRevisionHistory::class)->append($user, $id, 0, [
            'action' => 'create', 'after_snapshot' => $snapshot, 'reason' => 'Initial purchase posting', 'device_id' => $header['device_id'],
            'financial_effective_at' => $now, 'supplier_balance_delta' => $totals['total'],
            'resulting_supplier_id' => $supplier->id, 'allocation_changes_snapshot' => $allocations, 'idempotency_request_id' => $requestId,
        ]);
        app(PostingAudit::class)->record($request, 'purchase.created', 'purchase', $id, null, $snapshot);

        return ApiResponse::data($snapshot, 201);
    }
}
