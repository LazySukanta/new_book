<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\DocumentNumberService;
use App\Services\InvoiceJson;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\StockProjection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaleReturnController
{
    public function store(Request $request, int $sale, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('sales.return'), 403);
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'refund_mode' => 'sometimes|required|in:cash,upi,card,bank,credit_adjust',
            'items' => 'required|array|list|min:1|max:100',
            'items.*' => 'array:sale_item_id,qty',
            'items.*.sale_item_id' => 'required|integer|min:1|distinct',
            'items.*.qty' => 'required|string',
        ]);

        return $transaction->run($request, 'sale.return', $data + ['sale_id' => $sale],
            fn () => $this->post($request, $sale, $data));
    }

    public function invoice(Request $request, int $return): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('sales.return') || $actor->can('reports.view'), 403);
        $record = DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where(['sale_returns.id' => $return, 'sales.tenant_id' => $actor->tenant_id,
                'sales.branch_id' => $request->attributes->get('branch')->id])
            ->select('sale_returns.*')->first();
        abort_unless($record, 404);
        $snapshot = json_decode($record->invoice_snapshot, true, flags: JSON_THROW_ON_ERROR);

        return ApiResponse::data(InvoiceJson::fromSnapshot($snapshot) + [
            'id' => $return, 'status' => $record->status,
            'settlement' => [
                'credit_adjusted' => $snapshot['credit_adjusted'],
                'refund' => $snapshot['refund'],
                'retained_credit' => $snapshot['retained_credit'],
            ],
        ]);
    }

    private function post(Request $request, int $sale, array $data): JsonResponse
    {
        $actor = $request->user();
        $branch = $actor->accessibleBranches()->lockForUpdate()->findOrFail($request->attributes->get('branch')->id);
        $sales = DB::table('sales')->where(['id' => $sale, 'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id]);
        $customerId = (clone $sales)->value('customer_id');
        abort_unless($customerId, 404);
        $customer = DB::table('customers')->where(['id' => $customerId, 'tenant_id' => $actor->tenant_id])->lockForUpdate()->first();
        $original = $sales->lockForUpdate()->first();
        if ($original->status !== 'completed') {
            return ApiResponse::conflict('DOCUMENT_FINALIZED', 'Only completed sales can be returned.');
        }
        $invoice = json_decode($original->invoice_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $originalItems = collect($invoice['items'])->keyBy('id');
        $prior = DB::table('sale_return_items as item')->join('sale_returns as note', 'note.id', '=', 'item.sale_return_id')
            ->where(['note.sale_id' => $sale, 'note.status' => 'issued'])->select('item.*')->get()->groupBy('sale_item_id');
        $totals = array_fill_keys(['taxable', 'cgst', 'sgst', 'igst', 'round_off', 'total'], '0.00');
        $lines = [];
        foreach ($data['items'] as $item) {
            $source = $originalItems[$item['sale_item_id']] ?? null;
            abort_unless($source, 404);
            $decimal = $source['product']['allow_decimal'] ?? (bool) DB::table('units')->where('id', $source['product']['unit_id'])->value('allow_decimal');
            $qty = BigDecimal::of(Money::quantity($item['qty'], $decimal));
            $previous = ($prior[$source['id']] ?? collect())->map(fn ($line) => json_decode($line->line_snapshot, true, flags: JSON_THROW_ON_ERROR));
            $returned = $qty;
            foreach ($previous as $line) {
                $returned = $returned->plus($line['qty']);
            }
            if ($returned->isGreaterThan($source['qty'])) {
                throw ValidationException::withMessages(['items' => 'Return quantity exceeds the remaining sold quantity.']);
            }
            $line = array_intersect_key($source, array_flip(['product_id', 'product', 'unit_price', 'price_includes_tax', 'rate_bp']));
            $line += ['sale_item_id' => $source['id'], 'qty' => (string) $qty];
            $lineTotal = BigDecimal::zero()->toScale(2);
            foreach (['taxable', 'cgst', 'sgst', 'igst', 'line_discount', 'bill_discount_share'] as $field) {
                $line[$field] = $this->portion($source[$field], $returned, $source['qty'], $previous->pluck($field)->all(), 2);
                if (array_key_exists($field, $totals)) {
                    $totals[$field] = (string) BigDecimal::of($totals[$field])->plus($line[$field]);
                    $lineTotal = $lineTotal->plus($line[$field]);
                }
            }
            $line['line_total'] = (string) $lineTotal;
            $line['cost'] = $source['cost'];
            foreach (['cost_total' => 2, 'estimated_qty' => 3] as $field => $scale) {
                $line['cost'][$field] = $this->portion($source['cost'][$field], $returned, $source['qty'], $previous->pluck('cost.'.$field)->all(), $scale);
            }
            $totals['total'] = (string) BigDecimal::of($totals['total'])->plus($lineTotal);
            $lines[] = $line;
        }

        $amount = BigDecimal::of($totals['total']);
        $debt = BigDecimal::min($amount, BigDecimal::of($original->balance_amount));
        $excess = $amount->minus($debt);
        $mode = $data['refund_mode'] ?? null;
        $walkIn = (bool) $customer->is_system;
        if ($excess->isPositive() && ($mode === null || ($walkIn && $mode === 'credit_adjust'))) {
            throw ValidationException::withMessages(['refund_mode' => 'Choose an eligible settlement method for the excess credit.']);
        }
        $refund = $mode === 'credit_adjust' ? BigDecimal::zero()->toScale(2) : $excess;
        $now = now();
        $snapshot = array_intersect_key($invoice, array_flip(['shop', 'branch', 'customer', 'sale_type', 'place_of_supply_code', 'place_of_supply_name', 'is_interstate'])) + [
            'document_type' => 'credit_note', 'title' => 'CREDIT NOTE', 'date' => $now->toIso8601String(),
            'original_invoice_no' => $original->invoice_no, 'original_invoice_date' => $invoice['issued_at'],
            'reason' => $data['reason'], 'items' => $lines, 'totals' => $totals,
            'credit_adjusted' => (string) $debt, 'refund' => (string) $refund, 'retained_credit' => (string) $excess->minus($refund),
        ];
        $id = DB::table('sale_returns')->insertGetId([
            'sale_id' => $sale, 'created_by' => $actor->id, 'issued_at' => $now, 'reason' => $data['reason'],
            'total' => (string) $amount, 'credit_adjusted' => (string) $debt, 'refund_amount' => (string) $refund,
            'invoice_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        foreach (collect($lines)->sortBy('product_id') as $line) {
            $itemId = DB::table('sale_return_items')->insertGetId([
                'sale_return_id' => $id, 'sale_item_id' => $line['sale_item_id'], 'qty' => $line['qty'],
                'line_snapshot' => json_encode($line, JSON_THROW_ON_ERROR),
            ]);
            $this->restoreStock($actor->tenant_id, $branch->id, $actor->id, $itemId, $line);
        }
        $registry = app(DocumentNumberService::class)->issue($actor, $branch, $request->attributes->get('device'), 'credit_note', $now,
            function (string $number) use ($id): int {
                DB::table('sale_returns')->where('id', $id)->update(['credit_note_no' => $number]);

                return $id;
            });
        $payment = null;
        if ($refund->isPositive()) {
            $payment = DB::table('payments')->insertGetId([
                'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'customer_id' => $original->customer_id,
                'direction' => 'out', 'purpose' => 'refund', 'mode' => $mode, 'amount' => (string) $refund,
                'paid_at' => $now, 'created_at' => $now, 'created_by' => $actor->id, 'reason' => $data['reason'],
            ]);
        }
        DB::table('sales')->where('id', $sale)->update(['balance_amount' => (string) BigDecimal::of($original->balance_amount)->minus($debt)]);
        $snapshot['invoice_no'] = $registry->document_no;
        DB::table('sale_returns')->where('id', $id)->update([
            'issued_document_id' => $registry->id, 'refund_payment_id' => $payment, 'invoice_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        $result = $snapshot + ['id' => $id, 'status' => 'issued'];
        app(PostingAudit::class)->record($request, 'sale.returned', 'sale_return', $id, null, $result, $data['reason']);

        return ApiResponse::data($result, 201);
    }

    private function portion(string $original, BigDecimal $returned, string $sold, array $previous, int $scale): string
    {
        $cumulative = BigDecimal::of($original)->multipliedBy($returned)->dividedBy($sold, $scale, RoundingMode::HalfUp);
        foreach ($previous as $amount) {
            $cumulative = $cumulative->minus($amount);
        }

        return (string) $cumulative;
    }

    private function restoreStock(int $tenant, int $branch, int $actor, int $item, array $line): void
    {
        $scope = ['tenant_id' => $tenant, 'branch_id' => $branch, 'product_id' => $line['product_id']];
        $projection = app(StockProjection::class);
        $balance = $projection->lock($scope);
        $cost = $line['cost'];
        $qty = BigDecimal::of($balance->qty)->plus($line['qty']);
        $value = BigDecimal::of($balance->inventory_value)->plus($cost['cost_total']);
        $after = $qty->isZero() || ($qty->isPositive() && $value->isNegative()) ? BigDecimal::zero()->toScale(2) : $value;
        $projection->append($scope, [
            'type' => 'sale_return', 'sale_return_item_id' => $item, 'created_by' => $actor, 'posted_at' => now(),
            'qty_change' => $line['qty'], 'qty_after' => (string) $qty, 'value_after' => (string) $after,
            'value_change' => (string) $after->minus($balance->inventory_value), 'valuation_variance' => (string) $after->minus($value),
            'unit_cost' => $cost['unit_cost'], 'cost_source' => $cost['cost_source'], 'cost_estimated' => $cost['cost_estimated'],
            'estimated_qty' => $cost['estimated_qty'], 'estimated_unit_cost' => $cost['estimated_unit_cost'],
        ], $balance->last_known_unit_cost);
    }
}
