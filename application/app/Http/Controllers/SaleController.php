<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Customer;
use App\Models\Product;
use App\Services\DocumentNumberService;
use App\Services\InvoiceJson;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\SalePricing;
use App\Services\ShopCalendar;
use App\Services\StockPosting;
use App\Services\SupplierAccountPosting;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaleController
{
    public function show(Request $request, int $sale): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('sales.create') || $actor->can('reports.view'), 403);
        $branch = $request->attributes->get('branch');
        $record = DB::table('sales')->where(['id' => $sale, 'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id])
            ->when(! $actor->can('reports.view'), fn ($query) => $query->where('cashier_id', $actor->id))->first();
        abort_unless($record, 404);

        if (in_array($record->status, ['held', 'discarded'], true)) {
            return ApiResponse::data($this->heldData($record));
        }

        $snapshot = json_decode($record->invoice_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $snapshot['note'] = $record->note;

        return ApiResponse::data($snapshot + [
            'id' => $record->id, 'issued_document_id' => $record->issued_document_id, 'status' => $record->status,
            'paid_amount' => $record->paid_amount, 'balance_amount' => $record->balance_amount,
        ]);
    }

    public function invoice(Request $request, int $sale): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('sales.create') || $actor->can('reports.view'), 403);

        return DB::transaction(function () use ($request, $actor, $sale) {
            $record = $this->heldScope($request)->where('id', $sale)->whereNotNull('issued_document_id')
                ->when(! $actor->can('reports.view'), fn ($query) => $query->where('cashier_id', $actor->id))->first();
            abort_unless($record, 404);

            $snapshot = json_decode($record->invoice_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $snapshot['note'] = $record->note;
            $payments = DB::table('payment_allocations as allocation')
                ->leftJoin('payment_allocations as original', 'original.id', '=', 'allocation.reversal_of_id')
                ->join('payments as payment', 'payment.id', '=', DB::raw('COALESCE(original.payment_id, allocation.payment_id)'))
                ->where('allocation.sale_id', $sale)->select('payment.id', 'payment.mode', 'payment.paid_at')
                ->selectRaw('SUM(allocation.amount) AS amount')->groupBy('payment.id', 'payment.mode', 'payment.paid_at')
                ->havingRaw('SUM(allocation.amount) > 0')->orderBy('payment.paid_at')->orderBy('payment.id')->get()
                ->map(fn ($payment) => [
                    'mode' => $payment->mode, 'amount' => Money::amount($payment->amount),
                    'paid_at' => \Illuminate\Support\Carbon::parse($payment->paid_at)->toIso8601String(),
                ]);
            return ApiResponse::data(InvoiceJson::fromSnapshot($snapshot) + [
                'id' => $record->id, 'status' => $record->status,
                'settlement' => [
                    'paid_amount' => Money::amount($record->paid_amount),
                    'balance_amount' => Money::amount($record->balance_amount),
                    'payments' => $payments,
                    'opening_credit_applied' => (string) BigDecimal::of(DB::table('party_credit_allocations')
                        ->where('sale_id', $sale)->sum('amount'))->toScale(2),
                    'credit_note_adjusted' => (string) BigDecimal::of(DB::table('sale_returns')
                        ->where(['sale_id' => $sale, 'status' => 'issued'])->sum('credit_adjusted'))->toScale(2),
                ],
            ]);
        });
    }

    public function held(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);

        return ApiResponse::data($this->heldScope($request)->where('status', 'held')->orderByDesc('id')->get()
            ->map(fn ($sale) => $this->heldData($sale)));
    }

    public function discard(Request $request, int $sale, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $transaction->run($request, 'sale.discard', $data + ['sale_id' => $sale], function () use ($request, $sale, $data) {
            $record = $this->heldScope($request)->where('id', $sale)->lockForUpdate()->first();
            abort_unless($record, 404);
            if ($record->status !== 'held') {
                return ApiResponse::conflict('DOCUMENT_FINALIZED', 'This held sale is closed.');
            }
            $changes = ['status' => 'discarded', 'discarded_at' => now(), 'discarded_by' => $request->user()->id, 'discard_reason' => $data['reason']];
            DB::table('sales')->where('id', $sale)->update($changes);
            app(PostingAudit::class)->record($request, 'sale.discarded', 'sale', $sale, $this->heldData($record), $changes, $data['reason']);

            return ApiResponse::data(['id' => $sale, 'status' => 'discarded']);
        });
    }

    private function heldScope(Request $request): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales')->where([
            'tenant_id' => $request->user()->tenant_id,
            'branch_id' => $request->attributes->get('branch')->id,
        ]);
    }

    private function heldData(object $sale): array
    {
        return [
            'id' => $sale->id, 'status' => $sale->status, 'held_at' => $sale->held_at, 'held_by' => $sale->held_by,
            'cart' => json_decode($sale->held_cart_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'pricing' => json_decode($sale->held_pricing_snapshot, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function store(Request $request, PostingTransaction $transaction, bool $verifyHeldPricing = false): JsonResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'status' => 'sometimes|in:held,completed',
            'held_sale_id' => 'sometimes|nullable|integer|min:1',
            'acknowledged_pricing_fingerprint' => [$verifyHeldPricing ? 'required' : 'required_unless:status,held', 'regex:/\A[a-f0-9]{64}\z/'],
            'customer_id' => 'sometimes|nullable|integer|min:1',
            'sale_type' => 'sometimes|required|in:retail,wholesale',
            'place_of_supply_override' => 'sometimes|nullable|string',
            'items' => 'required|array|list|min:1|max:100',
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'bill_discount' => 'sometimes|nullable|array',
            'payments' => 'prohibited_if:status,held|sometimes|array|list|max:10',
            'payments.*' => 'array:mode,amount,reference',
            'payments.*.mode' => 'required|in:cash,upi,card,bank',
            'payments.*.amount' => 'required|string',
            'payments.*.reference' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string|max:1000',
            'credit_override_reason' => 'sometimes|required|string|max:255',
        ]);
        $data['items'] = $request->input('items');

        return $transaction->run($request, 'sale.post', $data, fn () => $this->post($request, $data, $verifyHeldPricing));
    }

    private function post(Request $request, array $data, bool $verifyHeldPricing): JsonResponse
    {
        $actor = $request->user();
        $device = $request->attributes->get('device');
        $branch = $actor->accessibleBranches()->lockForUpdate()->findOrFail($request->attributes->get('branch')->id);
        $customer = Customer::where(['tenant_id' => $actor->tenant_id, 'is_active' => true])
            ->when(isset($data['customer_id']), fn ($query) => $query->whereKey($data['customer_id']), fn ($query) => $query->where('is_system', true))
            ->lockForUpdate()->firstOrFail();
        $accounts = app(SupplierAccountPosting::class);
        $holding = ($data['status'] ?? 'completed') === 'held';
        $account = $holding || $customer->is_system
            ? (object) ['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id]
            : $accounts->account($request, $customer->id, customer: true);
        $held = null;
        if (isset($data['held_sale_id'])) {
            $held = $this->heldScope($request)->where('id', $data['held_sale_id'])->lockForUpdate()->first();
            abort_unless($held, 404);
            if ($held->status !== 'held') {
                return ApiResponse::conflict('DOCUMENT_FINALIZED', 'This held sale is closed.');
            }
        }
        $products = Product::where('tenant_id', $actor->tenant_id)->whereIn('id', array_column($data['items'], 'product_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        DB::table('tax_rates')->whereIn('id', $products->pluck('tax_rate_id'))->orderBy('id')->lockForUpdate()->get();
        $settings = DB::table('shop_settings')->where('tenant_id', $actor->tenant_id)->lockForUpdate()->first();
        $preview = app(SalePricing::class)->calculate($actor, $branch, $data);
        if ((! $holding || $verifyHeldPricing) && ! hash_equals($preview['pricing_fingerprint'], $data['acknowledged_pricing_fingerprint'])) {
            return response()->json(['code' => 'PRICE_CHANGED', 'message' => 'Review the updated pricing.', 'data' => $preview], 409);
        }
        $total = BigDecimal::of($preview['totals']['total']);
        $paid = BigDecimal::zero()->toScale(2);
        foreach ($data['payments'] ?? [] as $payment) {
            $amount = BigDecimal::of(Money::amount($payment['amount']));
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['payments' => 'Payments must be positive.']);
            }
            $paid = $paid->plus($amount);
        }
        $due = $total->minus($paid);
        if (! $holding && ($due->isNegative() || ($customer->is_system && ! $due->isZero()))) {
            throw ValidationException::withMessages(['payments' => 'Payment cannot exceed the total and Walk-in must pay in full.']);
        }
        $creditOverride = null;
        if (! $holding && $due->isPositive() && $this->creditExposure($customer, $branch->id, $due)->isGreaterThan($customer->credit_limit)) {
            if (! $actor->can('sales.override_credit') || ! isset($data['credit_override_reason'])) {
                return ApiResponse::conflict('CREDIT_LIMIT_EXCEEDED', 'This sale would exceed the recorded customer credit limit.');
            }
            $creditOverride = $data['credit_override_reason'];
        }
        $now = now();
        $stateNames = DB::table('gst_states')->whereIn('code', array_filter([
            $customer->state_code, $branch->state_code, $preview['place_of_supply_code'],
        ]))->pluck('name', 'code');
        $snapshot = $preview + [
            'customer' => $customer->only('id', 'name', 'phone', 'address', 'gstin', 'state_code')
                + ['state_name' => $stateNames[$customer->state_code] ?? null],
            'branch' => $branch->only('id', 'name', 'code', 'address', 'phone', 'gstin', 'state_code')
                + ['state_name' => $stateNames[$branch->state_code] ?? null],
            'place_of_supply_name' => $stateNames[$preview['place_of_supply_code']] ?? null,
            'shop' => $settings ? array_intersect_key((array) $settings, array_flip(['name', 'legal_name', 'gst_registration_type', 'footer'])) : null,
            'note' => $data['note'] ?? null,
            'cashier' => $actor->name,
        ];
        $values = [
            'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'cashier_id' => $actor->id, 'device_id' => $device->id, 'financial_year' => ShopCalendar::financialYear($now),
            'document_type' => $preview['document_type'], 'sale_type' => $preview['sale_type'], 'issued_at' => $holding ? null : $now,
            'pricing_fingerprint' => $preview['pricing_fingerprint'], 'invoice_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'total' => (string) $total, 'paid_amount' => '0.00', 'balance_amount' => (string) $total, 'note' => $data['note'] ?? null,
        ];
        if ($holding) {
            $cart = array_intersect_key($data, array_flip(['items', 'customer_id', 'sale_type', 'place_of_supply_override', 'bill_discount', 'note']));
            $cart['customer_id'] = $customer->id;
            $values += [
                'status' => 'held', 'held_at' => now(), 'held_by' => $actor->id,
                'held_cart_snapshot' => json_encode($cart, JSON_THROW_ON_ERROR),
                'held_pricing_snapshot' => json_encode($preview, JSON_THROW_ON_ERROR),
                'held_pricing_fingerprint' => $preview['pricing_fingerprint'],
            ];
            $values['balance_amount'] = '0.00';
        } else {
            $values += ['status' => 'completed', 'completed_at' => $now, 'completed_by' => $actor->id];
        }
        if ($held) {
            $sale = $held->id;
            DB::table('sales')->where('id', $sale)->update($values);
            DB::table('sale_items')->where('sale_id', $sale)->whereNotIn('product_id', array_column($preview['items'], 'product_id'))->delete();
        } else {
            $sale = DB::table('sales')->insertGetId($values);
        }
        $units = DB::table('units')->whereIn('id', $products->pluck('unit_id'))->get()->keyBy('id');
        $lines = collect($preview['items'])->keyBy('product_id')->sortKeys();
        foreach ($lines as $productId => $line) {
            $unit = $units[$products[$productId]->unit_id];
            $line['product'] = $products[$productId]->only('id', 'sku', 'name', 'hsn', 'unit_id')
                + ['unit' => $unit->code, 'allow_decimal' => (bool) $unit->allow_decimal];
            $key = ['sale_id' => $sale, 'product_id' => $productId];
            DB::table('sale_items')->updateOrInsert($key, [
                'qty' => $line['qty'], 'line_snapshot' => json_encode($line, JSON_THROW_ON_ERROR),
                'pricing_fingerprint' => $preview['pricing_fingerprint'],
            ]);
            if ($holding) {
                continue;
            }
            $item = DB::table('sale_items')->where($key)->value('id');
            $issue = app(StockPosting::class)->issueSale($actor, $branch->id, $productId, $item, $line['qty']);
            $cost = [
                'stock_ledger_id' => $issue->id, 'unit_cost' => $issue->unit_cost,
                'cost_total' => (string) BigDecimal::of($issue->value_change)->negated(),
                'cost_estimated' => $issue->cost_estimated, 'estimated_qty' => $issue->estimated_qty,
                'estimated_unit_cost' => $issue->estimated_unit_cost, 'cost_source' => $issue->cost_source,
            ];
            DB::table('sale_items')->where('id', $item)->update(['cost_snapshot' => json_encode($cost, JSON_THROW_ON_ERROR)]);
            $lines[$productId] = $line + ['id' => $item, 'cost' => $cost];
        }
        if ($holding) {
            $result = $this->heldData(DB::table('sales')->find($sale));
            app(PostingAudit::class)->record($request, 'sale.held', 'sale', $sale, $held ? $this->heldData($held) : null, $result);

            return ApiResponse::data($result, $held ? 200 : 201);
        }
        $snapshot['items'] = array_map(fn (array $line) => $lines[$line['product_id']], $preview['items']);
        $registry = app(DocumentNumberService::class)->issue($actor, $branch, $device, $preview['document_type'], $now, function (string $number) use ($sale): int {
            DB::table('sales')->where('id', $sale)->update(['invoice_no' => $number]);

            return $sale;
        });
        $snapshot['invoice_no'] = $registry->document_no;
        $snapshot['issued_at'] = $now->toIso8601String();
        DB::table('sales')->where('id', $sale)->update([
            'issued_document_id' => $registry->id, 'invoice_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        foreach ($data['payments'] ?? [] as $payment) {
            $receipt = $accounts->payment($request, $account, $payment + ['auto_allocate' => false]);
            $accounts->apply($request, $account, $receipt->id, [
                'sale_id' => $sale, 'amount' => $receipt->amount, 'effective_at' => $receipt->paid_at->toIso8601String(),
            ], 'automatic');
        }
        $result = $snapshot + [
            'id' => $sale, 'issued_document_id' => $registry->id, 'status' => 'completed',
            'paid_amount' => (string) $paid, 'balance_amount' => (string) $due,
            'opening_balance_incomplete' => ! $customer->is_system && DB::table('branches')->where(['tenant_id' => $actor->tenant_id, 'is_active' => true])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('party_accounts')
                    ->whereColumn('party_accounts.branch_id', 'branches.id')->where('customer_id', $customer->id)
                    ->where('opening_status', '!=', 'not_entered'))->exists(),
        ];
        app(PostingAudit::class)->record($request, 'sale.completed', 'sale', $sale, $held ? $this->heldData($held) : null, $result, $creditOverride);

        return ApiResponse::data($result, 201);
    }

    private function creditExposure(Customer $customer, int $branch, BigDecimal $due): BigDecimal
    {
        $scope = ['tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id];
        $opening = DB::table('party_opening_entries')->join('party_accounts', 'party_accounts.id', '=', 'party_opening_entries.party_account_id')
            ->where('party_accounts.tenant_id', $customer->tenant_id)->where('party_accounts.customer_id', $customer->id)
            ->select('party_accounts.branch_id', 'party_opening_entries.signed_amount as amount');
        $sales = DB::table('sales')->where($scope + ['status' => 'completed'])->select('branch_id', 'total as amount');
        $payments = DB::table('payments')->where($scope + ['balance_effect' => 'normal'])->select('branch_id')
            ->selectRaw("CASE WHEN direction = 'in' THEN -amount ELSE amount END AS amount");
        $returns = DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sales.tenant_id', $customer->tenant_id)->where('sales.customer_id', $customer->id)
            ->where('sale_returns.status', 'issued')->select('sales.branch_id')->selectRaw('-sale_returns.total AS amount');
        $balances = DB::query()->fromSub($opening->unionAll($sales)->unionAll($payments)->unionAll($returns), 'events')
            ->select('branch_id')->selectRaw('SUM(amount) AS balance')->groupBy('branch_id')->pluck('balance', 'branch_id');
        $balances[$branch] = BigDecimal::of($balances[$branch] ?? '0.00')->plus($due);

        return BigDecimal::sum(...$balances->values()->map(fn ($balance) => BigDecimal::max(BigDecimal::zero(), BigDecimal::of($balance)))->all());
    }
}
