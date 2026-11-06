<?php

namespace App\Services;

use App\Http\ApiResponse;
use App\Models\Product;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseCorrectionPosting
{
    public function prepare(Request $request, int $id, array $data): array
    {
        $actor = $request->user();
        $branch = $request->attributes->get('branch');
        $actor->accessibleBranches()->lockForUpdate()->findOrFail($branch->id);
        $query = DB::table('purchases')->where(['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'id' => $id]);
        $purchase = $query->first();
        abort_unless($purchase, 404);
        $supplierId = (int) ($data['supplier_id'] ?? $purchase->supplier_id);
        $suppliers = Supplier::where('tenant_id', $actor->tenant_id)->whereIn('id', [$purchase->supplier_id, $supplierId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless(isset($suppliers[$supplierId]), 404);
        $purchase = $query->lockForUpdate()->first();
        if ($purchase->current_revision !== (int) $data['expected_revision']) {
            $this->conflict('PURCHASE_REVISION_CONFLICT', 'The purchase changed. Generate a new preview.');
        }
        app(PurchaseCorrectionPreview::class)->assertCurrent($actor, $data['expected_revision'], $purchase->current_revision);
        abort_unless($purchase->status === 'posted', 409, 'Deleted purchases remain in history and cannot be edited.');
        $delete = $data['action'] === 'soft_delete';
        if ($delete) {
            foreach (['supplier_id', 'supplier_invoice_no', 'supplier_invoice_date', 'items', 'cost_includes_tax', 'input_tax_recoverable', 'tax_type', 'discount_amount'] as $field) {
                if (array_key_exists($field, $data)) {
                    throw ValidationException::withMessages([$field => 'Preview deletion separately from changes to the purchase.']);
                }
            }
        }
        if ($supplierId !== $purchase->supplier_id) {
            abort_unless($suppliers[$supplierId]->is_active, 422, 'Select an active supplier.');
        }
        $before = (array) $purchase;
        foreach (['supplier_snapshot', 'branch_snapshot'] as $field) {
            $before[$field] = json_decode($before[$field], true, flags: JSON_THROW_ON_ERROR);
        }
        $oldLines = DB::table('purchase_items')->where('purchase_id', $id)->orderBy('product_id')->get()->keyBy('product_id');
        $before['items'] = $oldLines->map(function (object $line) {
            $line = (array) $line;
            $line['product_snapshot'] = json_decode($line['product_snapshot'], true, flags: JSON_THROW_ON_ERROR);

            return $line;
        })->values()->all();
        $after = array_replace($before, array_intersect_key($data, array_flip([
            'supplier_id', 'supplier_invoice_no', 'supplier_invoice_date', 'cost_includes_tax', 'input_tax_recoverable', 'tax_type', 'discount_amount',
        ])));
        $after['supplier_id'] = $supplierId;
        $after['current_revision']++;
        if ($after['supplier_invoice_date'] > ShopCalendar::localDate(now())) {
            throw ValidationException::withMessages(['supplier_invoice_date' => 'The supplier invoice date cannot be in the future.']);
        }
        $after['normalized_invoice_no'] = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $after['supplier_invoice_no'])));
        abort_if($after['normalized_invoice_no'] === '', 422, 'Enter a valid supplier invoice number.');
        if (DB::table('purchases')->where(['supplier_id' => $supplierId, 'normalized_invoice_no' => $after['normalized_invoice_no']])->where('id', '!=', $id)->exists()) {
            $this->conflict('DUPLICATE_SUPPLIER_INVOICE', 'This supplier invoice number is already retained in purchase history.');
        }
        if ($supplierId !== $purchase->supplier_id) {
            $after['supplier_snapshot'] = $suppliers[$supplierId]->only('id', 'name', 'address', 'phone', 'gstin', 'state_code');
        }
        $inputs = $delete ? [] : ($data['items'] ?? $oldLines->where('is_removed', false)->map(fn ($line) => [
            'product_id' => $line->product_id, 'qty' => $line->qty, 'unit_cost' => $line->unit_cost, 'rate_bp' => $line->rate_bp,
        ])->values()->all());
        $productIds = array_unique([...$oldLines->keys()->all(), ...array_column($inputs, 'product_id')]);
        sort($productIds, SORT_NUMERIC);
        $products = Product::where('tenant_id', $actor->tenant_id)->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($products->count() === count($productIds), 404);
        $units = DB::table('units')->whereIn('id', $products->pluck('unit_id'))->pluck('allow_decimal', 'id');
        $confirmations = [];
        foreach ($inputs as &$line) {
            $product = $products[$line['product_id']];
            if (! isset($oldLines[$product->id]) || $oldLines[$product->id]->is_removed) {
                abort_unless($product->is_active, 422, 'New purchase lines require active products.');
            }
            $confirmations[$product->id] = $line['confirmed_remaining'] ?? null;
            unset($line['confirmed_remaining']);
            $line['qty'] = Money::quantity($line['qty'], (bool) $units[$product->unit_id]);
            $line['unit_cost'] = Money::amount($line['unit_cost']);
        }
        unset($line);
        $after['discount_amount'] = $delete ? '0.00' : Money::amount($after['discount_amount']);
        $calculated = $delete ? ['totals' => array_fill_keys(['taxable', 'cgst', 'sgst', 'igst', 'total'], '0.00'), 'lines' => []]
            : app(PurchaseTotals::class)->calculate($inputs, $after['discount_amount'], (bool) $after['cost_includes_tax'], $after['tax_type'], (bool) $after['input_tax_recoverable']);
        if (! $delete && array_intersect(array_keys($data), ['items', 'discount_amount', 'cost_includes_tax', 'input_tax_recoverable', 'tax_type']) === []) {
            $calculated['totals'] = array_intersect_key($before, array_flip(['taxable', 'cgst', 'sgst', 'igst', 'total']));
            $calculated['lines'] = $oldLines->where('is_removed', false)->map(fn ($line) => array_intersect_key((array) $line,
                array_flip(['product_id', 'qty', 'unit_cost', 'rate_bp', 'discount_share', 'taxable', 'cgst', 'sgst', 'igst', 'line_total', 'inventory_value', 'inventory_unit_cost'])))->values()->all();
        }
        $after = array_replace($after, $calculated['totals']);
        $after['unrounded_total'] = $after['total'];
        $after['status'] = $delete ? 'deleted' : 'posted';
        $newLines = collect($calculated['lines'])->keyBy('product_id');
        $effects = [];
        $lines = [];
        foreach ($productIds as $productId) {
            $old = $oldLines[$productId] ?? null;
            $new = $newLines[$productId] ?? null;
            $oldQty = $old && ! $old->is_removed ? $old->qty : '0.000';
            $oldCost = $old?->inventory_unit_cost ?? ($new['inventory_unit_cost'] ?? '0.000000000');
            $newQty = $new['qty'] ?? '0.000';
            $newCost = $new['inventory_unit_cost'] ?? $oldCost;
            $scope = ['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'product_id' => $productId];
            $balance = DB::table('stock_balances')->where($scope)->lockForUpdate()->first();
            // shortcut: R1 has no supplier returns; load linked return quantities and supplier dependencies when M22 enables them.
            $effect = app(PurchaseCorrectionPreview::class)->line(
                ['qty' => $oldQty, 'unit_cost' => $oldCost, 'returned_qty' => '0.000'],
                ['qty' => $newQty, 'unit_cost' => $newCost],
                ['qty' => $balance?->qty ?? '0.000', 'value' => $balance?->inventory_value ?? '0.00'], $confirmations[$productId] ?? null);
            if (BigDecimal::of($oldQty)->isEqualTo($newQty) && BigDecimal::of($oldCost)->isEqualTo($newCost)) {
                continue;
            }
            $effects[] = $effect + ['product_id' => $productId, 'purchase_item_id' => $old?->id, 'unit_cost' => $newCost,
                'cost_estimated' => false, 'cost_source' => 'owner_confirmation', 'last_ledger_id' => $balance?->last_ledger_id];
        }
        foreach ($productIds as $productId) {
            $old = $oldLines[$productId] ?? null;
            $new = $newLines[$productId] ?? null;
            if ($new === null) {
                $line = (array) $old;
                $line['is_removed'] = true;
                $line['product_snapshot'] = json_decode($line['product_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            } else {
                $line = $new + ['id' => $old?->id, 'purchase_id' => $id, 'is_removed' => false,
                    'price_includes_tax' => (bool) $after['cost_includes_tax'],
                    'product_snapshot' => $old ? json_decode($old->product_snapshot, true, flags: JSON_THROW_ON_ERROR)
                        : $products[$productId]->only('id', 'sku', 'name', 'hsn', 'unit_id')];
            }
            $lines[] = $line;
        }
        $after['items'] = $lines;
        $allocations = DB::table('payment_allocations as a')->where('a.purchase_id', $id)->where('a.amount', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_allocations as r')->whereColumn('r.reversal_of_id', 'a.id'))
            ->orderBy('a.id')->get()->map(fn ($a) => (array) $a)->all();
        $paid = BigDecimal::zero()->toScale(2);
        foreach ($allocations as $allocation) {
            $payment = DB::table('payments')->where('id', $allocation['payment_id'])->first();
            if ($payment->supplier_id !== $purchase->supplier_id || $payment->status !== 'posted' || $payment->direction !== 'out' || $payment->balance_effect !== 'normal') {
                $this->conflict('PURCHASE_ALLOCATION_CONFLICT', 'Resolve incompatible payment allocations before correcting this purchase.');
            }
            $used = BigDecimal::of(DB::table('payment_allocations')->where('payment_id', $payment->id)->sum('amount'));
            if ($used->isNegative() || $used->isGreaterThan($payment->amount)) {
                $this->conflict('PURCHASE_ALLOCATION_CONFLICT', 'The paid funds have incompatible dependent allocations. Reconcile them before correction.');
            }
            $paid = $paid->plus($allocation['amount']);
        }
        if (! $paid->isEqualTo($purchase->paid_amount)) {
            $this->conflict('PURCHASE_ALLOCATION_CONFLICT', 'Reconcile the purchase payment allocations before correction.');
        }
        $settlement = app(PurchaseCorrectionPreview::class)->settlement($purchase->supplier_id, $supplierId, $purchase->total, $after['total'], (string) $paid, false);
        $after['paid_amount'] = $settlement['retained_allocation'];
        $after['balance_amount'] = $settlement['resulting_payable'];
        $changes = [];
        $retain = BigDecimal::of($settlement['retained_allocation']);
        foreach ($allocations as $allocation) {
            $keep = BigDecimal::min($retain, BigDecimal::of($allocation['amount']));
            $retain = $retain->minus($keep);
            if (! $keep->isEqualTo($allocation['amount'])) {
                $changes[] = ['original' => $allocation, 'retain_amount' => (string) $keep->toScale(2)];
            }
        }
        $result = ['before' => $before, 'after' => $after, 'effects' => $effects, 'settlement' => $settlement,
            'allocation_changes' => $changes, 'allocations' => $allocations, 'action' => $data['action'], 'reason' => $data['reason']];
        $result['preview_hash'] = hash_hmac('sha256', json_encode($result, JSON_THROW_ON_ERROR), config('app.key'));

        return $result;
    }

    public function commit(Request $request, int $id, array $data, int $requestId): JsonResponse
    {
        $preview = $this->prepare($request, $id, $data);
        if (! hash_equals($preview['preview_hash'], $data['preview_hash'])) {
            $this->conflict('PURCHASE_PREVIEW_CHANGED', 'Stock, allocations or correction details changed. Review a fresh preview.');
        }
        $after = $preview['after'];
        $now = now();
        if ($data['action'] === 'soft_delete') {
            $after['deleted_at'] = $now->toDateTimeString();
            $after['deleted_by'] = $request->user()->id;
            $after['deletion_reason'] = $data['reason'];
        }
        foreach ($after['items'] as &$line) {
            $row = $line;
            unset($row['id']);
            $row['product_snapshot'] = json_encode($row['product_snapshot'], JSON_THROW_ON_ERROR);
            if ($line['id'] === null) {
                $line['id'] = DB::table('purchase_items')->insertGetId($row);
            } else {
                DB::table('purchase_items')->where('id', $line['id'])->update($row);
            }
        }
        unset($line);
        $header = $after;
        unset($header['items'], $header['id']);
        foreach (['supplier_snapshot', 'branch_snapshot'] as $field) {
            $header[$field] = json_encode($header[$field], JSON_THROW_ON_ERROR);
        }
        DB::table('purchases')->where('id', $id)->update($header);
        $after['correction_effects'] = $preview['effects'];
        $after['settlement'] = $preview['settlement'];
        $revision = app(PurchaseRevisionHistory::class)->append($request->user(), $id, $data['expected_revision'], [
            'action' => $data['action'], 'after_snapshot' => $after, 'before_snapshot' => $preview['before'],
            'reason' => $data['reason'], 'device_id' => $request->attributes->get('device')?->id,
            'financial_effective_at' => $now, 'supplier_balance_delta' => (string) BigDecimal::of($after['total'])->minus($preview['before']['total']),
            'previous_supplier_id' => $preview['before']['supplier_id'], 'resulting_supplier_id' => $after['supplier_id'],
            'allocation_changes_snapshot' => $preview['allocation_changes'], 'idempotency_request_id' => $requestId,
        ]);
        $lineIds = array_column($after['items'], 'id', 'product_id');
        foreach ($preview['effects'] as $effect) {
            $effect['purchase_item_id'] = $lineIds[$effect['product_id']];
            unset($effect['last_ledger_id']);
            $item = DB::table('purchase_revision_items')->insertGetId($effect + ['purchase_revision_id' => $revision, 'reason' => $data['reason']]);
            $scope = ['tenant_id' => $request->user()->tenant_id, 'branch_id' => $after['branch_id'], 'product_id' => $effect['product_id']];
            $projection = app(StockProjection::class);
            $balance = $projection->lock($scope);
            $projection->append($scope, [
                'type' => 'purchase_correction', 'purchase_revision_item_id' => $item, 'qty_change' => $effect['qty_change'],
                'qty_after' => $effect['qty_after'], 'value_change' => $effect['inventory_value_change'], 'value_after' => $effect['value_after'],
                'valuation_variance' => $effect['valuation_variance'], 'unit_cost' => $effect['unit_cost'],
                'cost_source' => $effect['cost_source'], 'created_by' => $request->user()->id, 'posted_at' => $now,
            ], $balance->last_known_unit_cost);
        }
        foreach ($preview['allocation_changes'] as $change) {
            $original = $change['original'];
            $common = ['payment_id' => $original['payment_id'], 'purchase_id' => $id, 'purchase_revision_id' => $revision,
                'effective_at' => $now, 'created_at' => $now, 'created_by' => $request->user()->id, 'application_kind' => $original['application_kind']];
            DB::table('payment_allocations')->insert($common + ['amount' => (string) BigDecimal::of($original['amount'])->negated(), 'reversal_of_id' => $original['id']]);
            if (BigDecimal::of($change['retain_amount'])->isPositive()) {
                DB::table('payment_allocations')->insert($common + ['amount' => $change['retain_amount']]);
            }
        }
        app(PostingAudit::class)->record($request, 'purchase.'.$data['action'], 'purchase', $id, $preview['before'], $after, $data['reason']);

        return ApiResponse::data($after);
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(ApiResponse::conflict($code, $message));
    }
}
