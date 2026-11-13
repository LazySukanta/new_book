<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\DocumentNumberService;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\StockProjection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PurchaseReturnController
{
    public function store(Request $request, int $purchase, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('purchases.manage'), 403);
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1', 'reason' => 'required|string|max:255',
            'supplier_document_no' => 'nullable|string|max:255', 'supplier_document_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'refund_amount' => 'required|string', 'refund_mode' => 'required|in:cash,upi,card,bank',
            'items' => 'required|array|list|min:1|max:100', 'items.*' => 'array:purchase_item_id,qty',
            'items.*.purchase_item_id' => 'required|integer|min:1|distinct', 'items.*.qty' => 'required|string',
        ]);
        if ($request->routeIs('workspace.purchases.return-preview')) {
            return DB::transaction(fn () => $this->post($request, $purchase, $data, true));
        }
        return $transaction->run($request, 'purchase.return', $data + ['purchase_id' => $purchase],
            fn () => $this->post($request, $purchase, $data));
    }

    private function post(Request $request, int $id, array $data, bool $preview = false): JsonResponse
    {
        $actor = $request->user();
        $branch = $actor->accessibleBranches()->lockForUpdate()->findOrFail($request->attributes->get('branch')->id);
        $query = DB::table('purchases')->where(['id' => $id, 'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id]);
        $original = (clone $query)->first();
        abort_unless($original, 404);
        DB::table('suppliers')->where('id', $original->supplier_id)->lockForUpdate()->first();
        $purchase = $query->lockForUpdate()->first();
        abort_unless($purchase->status === 'posted', 409, 'Only active purchases can be returned.');
        if ($purchase->current_revision !== (int) $data['expected_revision']) {
            return ApiResponse::conflict('PURCHASE_REVISION_CONFLICT', 'The purchase changed. Review it before returning goods.');
        }
        $refund = BigDecimal::of(Money::amount($data['refund_amount']));
        abort_if($refund->isPositive() && ! $actor->can('payments.pay_supplier'), 403);
        $lines = [];
        $totals = array_fill_keys(['total', 'taxable', 'cgst', 'sgst', 'igst'], BigDecimal::zero()->toScale(2));
        usort($data['items'], fn ($a, $b) => $a['purchase_item_id'] <=> $b['purchase_item_id']);
        foreach ($data['items'] as $input) {
            $line = DB::table('purchase_items')->where(['id' => $input['purchase_item_id'], 'purchase_id' => $id, 'is_removed' => false])->lockForUpdate()->first();
            abort_unless($line, 404);
            $product = DB::table('products')->where('id', $line->product_id)->lockForUpdate()->first();
            $decimal = (bool) DB::table('units')->where('id', $product->unit_id)->value('allow_decimal');
            $qty = BigDecimal::of(Money::quantity($input['qty'], $decimal));
            $returned = BigDecimal::of(DB::table('purchase_return_items')->where('purchase_item_id', $line->id)->sum('qty'));
            abort_if($qty->plus($returned)->isGreaterThan($line->qty), 422, 'Return quantity exceeds the remaining purchased quantity.');
            $scope = ['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'product_id' => $line->product_id];
            $balance = app(StockProjection::class)->lock($scope);
            abort_if($qty->isGreaterThan($balance->qty), 422, 'Insufficient physical stock for this supplier return.');
            $values = [];
            foreach (['taxable' => 'taxable', 'cgst' => 'cgst', 'sgst' => 'sgst', 'igst' => 'igst'] as $key => $field) {
                $previous = BigDecimal::zero();
                foreach (DB::table('purchase_return_items')->where('purchase_item_id', $line->id)->get() as $prior) {
                    $previous = $previous->plus(json_decode($prior->line_snapshot, true, flags: JSON_THROW_ON_ERROR)[$key]);
                }
                $value = BigDecimal::of($line->$field)->multipliedBy($returned->plus($qty))->dividedBy($line->qty, 2, RoundingMode::HalfUp)->minus($previous);
                abort_if($value->isNegative(), 409, 'Purchase correction conflicts with an earlier return amount.');
                $values[$key] = (string) $value;
                $totals[$key] = $totals[$key]->plus($value);
            }
            $values['total'] = (string) BigDecimal::of($values['taxable'])->plus($values['cgst'])->plus($values['sgst'])->plus($values['igst']);
            $totals['total'] = $totals['total']->plus($values['total']);
            $carrying = BigDecimal::of($balance->inventory_value)->multipliedBy($qty)->dividedBy($balance->qty, 2, RoundingMode::HalfUp);
            $acquisition = $qty->multipliedBy($line->inventory_unit_cost)->toScale(2, RoundingMode::HalfUp);
            $lines[] = $values + ['purchase_item_id' => $line->id, 'product_id' => $line->product_id, 'qty' => (string) $qty,
                'product_snapshot' => json_decode($line->product_snapshot, true, flags: JSON_THROW_ON_ERROR),
                'inventory_value' => (string) $carrying, 'valuation_variance' => (string) $acquisition->minus($carrying),
                'balance' => $balance, 'scope' => $scope];
        }
        $total = $totals['total'];
        $credit = BigDecimal::min($total, BigDecimal::max(BigDecimal::zero(), BigDecimal::of($purchase->balance_amount)));
        abort_if($refund->isGreaterThan($total->minus($credit)), 422, 'Refund exceeds the return credit remaining after purchase dues.');
        $now = now();
        $snapshot = array_map(fn ($value) => (string) $value, $totals) + [
            'purchase_id' => $id, 'purchase_revision' => $purchase->current_revision,
            'credit_adjusted' => (string) $credit, 'refund_amount' => (string) $refund,
            'retained_credit' => (string) $total->minus($credit)->minus($refund),
            'supplier_snapshot' => json_decode($purchase->supplier_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'items' => array_map(fn ($line) => array_diff_key($line, array_flip(['balance', 'scope'])), $lines),
        ];
        if ($preview) {
            return ApiResponse::data($snapshot);
        }
        $returnId = null;
        $issued = app(DocumentNumberService::class)->issue($actor, $branch, $request->attributes->get('device'), 'purchase_return', $now,
            function (string $number) use (&$returnId, $snapshot, $data, $actor, $now, $id) {
                $returnId = DB::table('purchase_returns')->insertGetId(array_intersect_key($snapshot, array_flip(['total', 'taxable', 'cgst', 'sgst', 'igst', 'credit_adjusted', 'refund_amount'])) + [
                    'purchase_id' => $id, 'document_no' => $number, 'issued_at' => $now, 'created_by' => $actor->id,
                    'reason' => $data['reason'], 'supplier_document_no' => $data['supplier_document_no'] ?? null,
                    'supplier_document_date' => $data['supplier_document_date'] ?? null, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                ]);
                return $returnId;
            });
        $paymentId = null;
        if ($refund->isPositive()) {
            $paymentId = DB::table('payments')->insertGetId([
                'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'supplier_id' => $purchase->supplier_id,
                'direction' => 'in', 'purpose' => 'supplier_refund', 'mode' => $data['refund_mode'], 'amount' => (string) $refund,
                'paid_at' => $now, 'created_at' => $now, 'created_by' => $actor->id, 'reason' => $data['reason'], 'reference' => $issued->document_no,
            ]);
        }
        DB::table('purchase_returns')->where('id', $returnId)->update(['issued_document_id' => $issued->id, 'refund_payment_id' => $paymentId]);
        foreach ($lines as $line) {
            $item = DB::table('purchase_return_items')->insertGetId(['purchase_return_id' => $returnId,
                'purchase_item_id' => $line['purchase_item_id'], 'qty' => $line['qty'],
                'line_snapshot' => json_encode(array_diff_key($line, array_flip(['balance', 'scope'])), JSON_THROW_ON_ERROR)]);
            $balance = $line['balance'];
            app(StockProjection::class)->append($line['scope'], [
                'type' => 'purchase_return', 'qty_change' => (string) BigDecimal::of($line['qty'])->negated(),
                'value_change' => (string) BigDecimal::of($line['inventory_value'])->negated(),
                'qty_after' => (string) BigDecimal::of($balance->qty)->minus($line['qty']),
                'value_after' => (string) BigDecimal::of($balance->inventory_value)->minus($line['inventory_value']),
                'valuation_variance' => $line['valuation_variance'], 'unit_cost' => (string) BigDecimal::of($line['inventory_value'])->dividedBy($line['qty'], 9, RoundingMode::HalfUp),
                'cost_estimated' => false, 'estimated_qty' => '0.000', 'cost_source' => 'carrying_value',
                'purchase_return_item_id' => $item, 'created_by' => $actor->id, 'posted_at' => $now,
            ], $balance->last_known_unit_cost);
        }
        $release = $total->minus($credit);
        foreach (DB::table('payment_allocations as a')->where('a.purchase_id', $id)->where('a.amount', '>', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('payment_allocations as r')->whereColumn('r.reversal_of_id', 'a.id'))
            ->orderByDesc('a.id')->lockForUpdate()->get() as $allocation) {
            if ($release->isZero()) {
                break;
            }
            $amount = BigDecimal::of($allocation->amount);
            $freed = BigDecimal::min($release, $amount);
            $common = ['payment_id' => $allocation->payment_id, 'purchase_id' => $id, 'effective_at' => $now,
                'created_at' => $now, 'created_by' => $actor->id, 'application_kind' => $allocation->application_kind];
            DB::table('payment_allocations')->insert($common + ['amount' => (string) $amount->negated(), 'reversal_of_id' => $allocation->id]);
            if ($amount->isGreaterThan($freed)) {
                DB::table('payment_allocations')->insert($common + ['amount' => (string) $amount->minus($freed)]);
            }
            $release = $release->minus($freed);
        }
        DB::table('purchases')->where('id', $id)->update([
            'balance_amount' => (string) BigDecimal::of($purchase->balance_amount)->minus($credit),
            'paid_amount' => (string) BigDecimal::of($purchase->paid_amount)->minus($total->minus($credit)->minus($release)),
        ]);
        $result = $snapshot + ['id' => $returnId, 'document_no' => $issued->document_no];
        app(PostingAudit::class)->record($request, 'purchase.returned', 'purchase_return', $returnId, null, $result, $data['reason']);
        return ApiResponse::data($result, 201);
    }
}
