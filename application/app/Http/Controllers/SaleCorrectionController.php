<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\ShopCalendar;
use App\Services\StockProjection;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaleCorrectionController
{
    public function cancel(Request $request, int $sale, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('sales.cancel'), 403);
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'refund_mode' => 'sometimes|required|in:cash,upi,card,bank,credit_adjust',
        ]);

        return $transaction->run($request, 'sale.cancel', $data + ['sale_id' => $sale], function () use ($request, $sale, $data) {
            $original = $this->lockSale($request, $sale);
            $this->authorizeWindow($request, $original->issued_at);
            if ($original->status !== 'completed' || DB::table('sale_returns')->where('sale_id', $sale)->exists()) {
                return ApiResponse::conflict('CANCELLATION_BLOCKED', 'The sale is closed or has return history.');
            }

            $allocations = $this->activeAllocations('payment_allocations', $sale)->get();
            $postingRequests = DB::table('audit_logs')->where([
                'tenant_id' => $original->tenant_id, 'action' => 'sale.completed', 'entity_id' => $sale,
            ])->select('request_id');
            $originalReceipts = DB::table('audit_logs')->where('tenant_id', $original->tenant_id)
                ->where('action', 'payment.created')->whereIn('request_id', $postingRequests)->pluck('entity_id');
            $paid = BigDecimal::zero()->toScale(2);
            foreach ($allocations as $allocation) {
                if ($originalReceipts->contains($allocation->payment_id)) {
                    $paid = $paid->plus($allocation->amount);
                }
            }
            $walkIn = DB::table('customers')->where('id', $original->customer_id)->value('is_system');
            $mode = $data['refund_mode'] ?? null;
            if ($paid->isPositive() && ($mode === null || ($walkIn && $mode === 'credit_adjust'))) {
                throw ValidationException::withMessages(['refund_mode' => 'Choose an eligible refund or retained-credit method.']);
            }
            $refund = $mode === 'credit_adjust' ? BigDecimal::zero()->toScale(2) : $paid;
            $now = now();
            foreach ($allocations as $allocation) {
                $this->detach('payment_allocations', $allocation, $request->user()->id);
            }
            foreach ($this->activeAllocations('party_credit_allocations', $sale)->get() as $allocation) {
                $this->detach('party_credit_allocations', $allocation, $request->user()->id);
            }
            $issues = DB::table('stock_ledger')->whereIn('sale_item_id', DB::table('sale_items')->where('sale_id', $sale)->select('id'))
                ->where('type', 'sale_issue')->orderBy('product_id')->get();
            foreach ($issues as $issue) {
                $this->reverseStock($request, $issue);
            }
            $refundId = null;
            if ($refund->isPositive()) {
                $refundId = DB::table('payments')->insertGetId([
                    'tenant_id' => $original->tenant_id, 'branch_id' => $original->branch_id, 'customer_id' => $original->customer_id,
                    'direction' => 'out', 'purpose' => 'refund', 'mode' => $mode, 'amount' => (string) $refund,
                    'paid_at' => $now, 'created_at' => $now, 'created_by' => $request->user()->id, 'reason' => $data['reason'],
                ]);
            }
            DB::table('sales')->where('id', $sale)->update(['status' => 'cancelled', 'balance_amount' => '0.00', 'paid_amount' => '0.00']);
            $this->cancelRegistry($request, $original->issued_document_id, $data['reason']);
            $result = ['id' => $sale, 'status' => 'cancelled', 'invoice_no' => $original->invoice_no,
                'refund' => (string) $refund, 'refund_payment_id' => $refundId, 'retained_credit' => (string) $paid->minus($refund)];
            app(PostingAudit::class)->record($request, 'sale.cancelled', 'sale', $sale, ['status' => 'completed'], $result, $data['reason']);

            return ApiResponse::data($result);
        });
    }

    public function reverseReturn(Request $request, int $return, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('sales.cancel'), 403);
        $data = $request->validate(['reason' => 'required|string|max:255', 'refund_recovered' => 'sometimes|boolean']);

        return $transaction->run($request, 'sale.return.cancel', $data + ['return_id' => $return], function () use ($request, $return, $data) {
            $sale = DB::table('sale_returns')->where('id', $return)->value('sale_id');
            abort_unless($sale, 404);
            $original = $this->lockSale($request, $sale);
            $note = DB::table('sale_returns')->where('id', $return)->lockForUpdate()->first();
            $this->authorizeWindow($request, $note->issued_at);
            if ($note->status !== 'issued' || DB::table('sale_returns')->where(['sale_id' => $sale, 'status' => 'issued'])->where('id', '>', $return)->exists()) {
                return ApiResponse::conflict('CANCELLATION_BLOCKED', 'Reverse later credit notes before reversing this note.');
            }
            $retained = BigDecimal::of($note->total)->minus($note->credit_adjusted)->minus($note->refund_amount);
            if ($retained->isPositive()) {
                $laterSale = $this->sales($request)->where('customer_id', $original->customer_id)
                    ->where('status', 'completed')->where('issued_at', '>=', $note->issued_at)->where('id', '!=', $sale)->exists();
                if ($laterSale) {
                    return ApiResponse::conflict('CANCELLATION_BLOCKED', 'Later sales may depend on the retained credit.');
                }
            }
            if ($note->refund_payment_id !== null) {
                if (! ($data['refund_recovered'] ?? false)) {
                    throw ValidationException::withMessages(['refund_recovered' => 'Confirm recovery of the issued refund.']);
                }
                $refund = DB::table('payments')->where('id', $note->refund_payment_id)->lockForUpdate()->first();
                abort_unless($refund->status === 'posted', 409, 'The refund has already been reversed.');
                DB::table('payments')->insert([
                    'tenant_id' => $original->tenant_id, 'branch_id' => $original->branch_id, 'customer_id' => $original->customer_id,
                    'direction' => 'in', 'purpose' => 'reversal', 'mode' => $refund->mode, 'amount' => $refund->amount,
                    'paid_at' => now(), 'created_at' => now(), 'created_by' => $request->user()->id,
                    'reversal_of_id' => $refund->id, 'reason' => $data['reason'],
                ]);
                DB::table('payments')->where('id', $refund->id)->update(['status' => 'reversed']);
            }
            $moves = DB::table('stock_ledger')->whereIn('sale_return_item_id', DB::table('sale_return_items')->where('sale_return_id', $return)->select('id'))
                ->orderBy('product_id')->get();
            foreach ($moves as $move) {
                $this->reverseStock($request, $move);
            }
            DB::table('sales')->where('id', $sale)->increment('balance_amount', $note->credit_adjusted);
            DB::table('sale_returns')->where('id', $return)->update(['status' => 'cancelled']);
            $this->cancelRegistry($request, $note->issued_document_id, $data['reason']);
            $result = ['id' => $return, 'status' => 'cancelled', 'invoice_no' => $note->credit_note_no];
            app(PostingAudit::class)->record($request, 'sale_return.cancelled', 'sale_return', $return, ['status' => 'issued'], $result, $data['reason']);

            return ApiResponse::data($result);
        });
    }

    public function comment(Request $request, int $sale, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->hasRole('Owner'), 403);
        $data = $request->validate(['note' => 'present|nullable|string|max:1000', 'reason' => 'required|string|max:255']);

        return $transaction->run($request, 'sale.comment', $data + ['sale_id' => $sale], function () use ($request, $sale, $data) {
            $record = $this->lockSale($request, $sale);
            abort_unless($record->issued_document_id, 409, 'The sale has no issued invoice.');
            DB::table('sales')->where('id', $sale)->update(['note' => $data['note']]);
            app(PostingAudit::class)->record($request, 'sale.comment_changed', 'sale', $sale,
                ['note' => $record->note], ['note' => $data['note']], $data['reason']);

            return ApiResponse::data(['id' => $sale, 'note' => $data['note']]);
        });
    }

    public function commentHistory(Request $request, int $sale): JsonResponse
    {
        abort_unless($request->user()->hasRole('Owner'), 403);
        abort_unless($this->sales($request)->where('id', $sale)->whereNotNull('issued_document_id')->exists(), 404);
        $history = DB::table('audit_logs')->where(['tenant_id' => $request->user()->tenant_id,
            'action' => 'sale.comment_changed', 'entity_type' => 'sale', 'entity_id' => $sale])->orderBy('id')->get()
            ->map(fn ($row) => [
                'before' => json_decode($row->before_json, true, flags: JSON_THROW_ON_ERROR)['note'],
                'after' => json_decode($row->after_json, true, flags: JSON_THROW_ON_ERROR)['note'],
                'reason' => $row->reason, 'user_id' => $row->user_id, 'created_at' => $row->created_at,
            ]);

        return ApiResponse::data($history);
    }

    private function sales(Request $request): Builder
    {
        return DB::table('sales')->where(['tenant_id' => $request->user()->tenant_id, 'branch_id' => $request->attributes->get('branch')->id]);
    }

    private function lockSale(Request $request, int $sale): object
    {
        $request->user()->accessibleBranches()->lockForUpdate()->findOrFail($request->attributes->get('branch')->id);
        $query = $this->sales($request)->where('id', $sale);
        $customer = (clone $query)->value('customer_id');
        abort_unless($customer, 404);
        DB::table('customers')->where('id', $customer)->lockForUpdate()->first();

        return $query->lockForUpdate()->first();
    }

    private function authorizeWindow(Request $request, ?string $issuedAt): void
    {
        abort_unless($issuedAt !== null, 409, 'The document has not been issued.');
        if (! $request->user()->hasRole('Owner')) {
            $days = DB::table('shop_settings')->where('tenant_id', $request->user()->tenant_id)->value('manager_cancel_window_days') ?? 0;
            $cutoff = ShopCalendar::localDate(now()->subDays($days));
            abort_if(ShopCalendar::localDate(CarbonImmutable::parse($issuedAt, 'UTC')) < $cutoff, 403, 'The cancellation window has closed.');
        }
    }

    private function activeAllocations(string $table, int $sale): Builder
    {
        return DB::table($table.' as allocation')->where('allocation.sale_id', $sale)->whereNull('allocation.reversal_of_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from($table.' as reversal')
                ->whereColumn('reversal.reversal_of_id', 'allocation.id'));
    }

    private function detach(string $table, object $allocation, int $actor): void
    {
        $row = (array) $allocation;
        unset($row['id']);
        $row['amount'] = (string) BigDecimal::of($allocation->amount)->negated();
        $row['effective_at'] = $row['created_at'] = now();
        $row['created_by'] = $actor;
        $row['reversal_of_id'] = $allocation->id;
        DB::table($table)->insert($row);
    }

    private function reverseStock(Request $request, object $movement): void
    {
        $scope = ['tenant_id' => $movement->tenant_id, 'branch_id' => $movement->branch_id, 'product_id' => $movement->product_id];
        $projection = app(StockProjection::class);
        $balance = $projection->lock($scope);
        $qty = BigDecimal::of($balance->qty)->minus($movement->qty_change);
        $value = BigDecimal::of($balance->inventory_value)->minus($movement->value_change);
        if (BigDecimal::of($movement->qty_change)->isPositive() && ($qty->isNegative() || $value->isNegative())) {
            abort(409, 'Available stock or value cannot support reversal of this credit note.');
        }
        $after = $qty->isZero() || ($qty->isPositive() && $value->isNegative()) ? BigDecimal::zero()->toScale(2) : $value;
        $projection->append($scope, [
            'type' => 'reversal', 'reversal_of_id' => $movement->id, 'created_by' => $request->user()->id, 'posted_at' => now(),
            'qty_change' => (string) BigDecimal::of($movement->qty_change)->negated(), 'qty_after' => (string) $qty,
            'value_change' => (string) $after->minus($balance->inventory_value), 'value_after' => (string) $after,
            'valuation_variance' => (string) $after->minus($value), 'unit_cost' => $movement->unit_cost,
            'cost_estimated' => $movement->cost_estimated, 'estimated_qty' => $movement->estimated_qty,
            'estimated_unit_cost' => $movement->estimated_unit_cost, 'cost_source' => $movement->cost_source,
        ], $balance->last_known_unit_cost);
    }

    private function cancelRegistry(Request $request, int $document, string $reason): void
    {
        DB::table('issued_documents')->where('id', $document)->update([
            'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancellation_reason' => $reason,
        ]);
    }
}
