<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierAccountPosting
{
    public function account(Request $request, int $party, bool $customer = false): object
    {
        $user = $request->user();
        $branch = $request->attributes->get('branch');
        $user->accessibleBranches()->lockForUpdate()->findOrFail($branch->id);
        $record = DB::table($customer ? 'customers' : 'suppliers')->where(['tenant_id' => $user->tenant_id, 'id' => $party])->lockForUpdate()->first();
        abort_unless($record, 404);
        abort_if($customer && $record->is_system, 422, 'Walk-in has no credit account.');
        $scope = ['tenant_id' => $user->tenant_id, 'branch_id' => $branch->id, ($customer ? 'customer_id' : 'supplier_id') => $party];
        DB::table('party_accounts')->insertOrIgnore($scope);

        return DB::table('party_accounts')->where($scope)->lockForUpdate()->first();
    }

    public function opening(Request $request, object $account, array $data): object
    {
        $amount = $this->positive($data['amount']);
        $effective = $this->date($data['effective_at']);
        $replaces = $data['replaces_entry_id'] ?? null;
        if ($replaces !== null) {
            abort_unless(DB::table('party_opening_entries')->where([
                'id' => $replaces, 'party_account_id' => $account->id, 'status' => 'reversed',
            ])->exists(), 404);
        }
        if (isset($data['reference']) && DB::table('party_opening_entries')->where([
            'party_account_id' => $account->id, 'reference' => $data['reference'],
        ])->exists()) {
            throw ValidationException::withMessages(['reference' => 'This opening reference already exists for the account.']);
        }
        $entry = [
            'party_account_id' => $account->id, 'entry_type' => $data['entry_type'],
            'signed_amount' => (string) ($data['entry_type'] === 'due' ? $amount : $amount->negated()),
            'effective_at' => $effective, 'created_at' => now(), 'created_by' => $request->user()->id,
            'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
            'reason' => $data['reason'], 'replaces_entry_id' => $replaces,
        ];
        $id = DB::table('party_opening_entries')->insertGetId($entry);
        $this->status($request, $account, 'entered', $effective, $data['reason']);
        app(PostingAudit::class)->record($request, 'opening.created', 'party_opening_entry', $id, null, $entry, $data['reason']);

        return (object) ($entry + ['id' => $id]);
    }

    public function status(Request $request, object $account, string $status, CarbonImmutable $effective, string $reason): array
    {
        if ($status === 'confirmed_zero' && ! BigDecimal::of(DB::table('party_opening_entries')
            ->where('party_account_id', $account->id)->sum('signed_amount'))->isZero()) {
            throw ValidationException::withMessages(['opening_status' => 'Reverse or correct the opening balance before confirming zero.']);
        }
        $data = ['opening_status' => $status, 'opening_status_effective_at' => $effective,
            'opening_confirmed_at' => now(), 'opening_confirmed_by' => $request->user()->id];
        DB::table('party_accounts')->where('id', $account->id)->update($data);
        app(PostingAudit::class)->record($request, 'opening.status', 'party_account', $account->id,
            ['opening_status' => $account->opening_status], $data, $reason);

        return $data;
    }

    public function reverseOpening(Request $request, object $account, int $id, array $data): object
    {
        $query = DB::table('party_opening_entries')->where('party_account_id', $account->id);
        $original = (clone $query)->where('id', $id)->lockForUpdate()->first();
        abort_unless($original, 404);
        abort_if($original->status === 'reversed' || $original->reversal_of_id !== null, 409, 'Opening entry already reversed or is a reversal.');
        abort_if(! BigDecimal::of(DB::table('payment_allocations')->where('party_opening_entry_id', $id)->sum('amount'))->isZero(),
            409, 'Reverse the applied allocations before reversing the opening entry.');
        if ($account->customer_id) {
            $credit = DB::table('party_credit_allocations')->where('party_opening_entry_id', $id)->orWhere('opening_due_id', $id);
            abort_if(! BigDecimal::of((clone $credit)->sum('amount'))->isZero(), 409, 'Reverse the applied opening credit first.');
        }
        $effective = $this->date($data['effective_at']);
        $this->assertDateOrder($effective, $original->effective_at);
        $lastAllocation = DB::table('payment_allocations')->where('party_opening_entry_id', $id)->max('effective_at');
        if ($lastAllocation !== null) {
            $this->assertDateOrder($effective, $lastAllocation);
        }
        if (isset($credit) && ($lastCredit = $credit->max('effective_at')) !== null) {
            $this->assertDateOrder($effective, $lastCredit);
        }
        $entry = [
            'party_account_id' => $account->id, 'entry_type' => 'reversal', 'signed_amount' => (string) BigDecimal::of($original->signed_amount)->negated(),
            'effective_at' => $effective, 'created_at' => now(), 'created_by' => $request->user()->id, 'reversal_of_id' => $id,
            'reason' => $data['reason'],
        ];
        $newId = DB::table('party_opening_entries')->insertGetId($entry);
        (clone $query)->where('id', $id)->update(['status' => 'reversed']);
        app(PostingAudit::class)->record($request, 'opening.reversed', 'party_opening_entry', $newId, (array) $original, $entry, $data['reason']);

        return (object) ($entry + ['id' => $newId]);
    }

    public function payment(Request $request, object $account, array $data): object
    {
        [$amount, $historical, $effective, $evidence] = $this->paymentIntent($request, $account, $data);
        $row = $this->scope($account) + [
            'direction' => $account->customer_id ? 'in' : 'out',
            'purpose' => $account->customer_id ? 'customer_receipt' : 'supplier_payment', 'mode' => $data['mode'], 'amount' => (string) $amount,
            'reference' => $data['reference'] ?? null, 'paid_at' => $effective, 'created_at' => now(), 'created_by' => $request->user()->id,
            'historical_entry' => $historical, 'included_in_opening_entry_id' => $evidence,
            'balance_effect' => $evidence === null ? 'normal' : 'included_in_opening', 'reason' => $data['reason'] ?? null,
        ];
        $id = DB::table('payments')->insertGetId($row);
        if ($evidence === null && ($data['auto_allocate'] ?? true)) {
            $opening = DB::table('party_opening_entries')->where('party_account_id', $account->id)
                ->where(['entry_type' => 'due', 'status' => 'posted'])->where('effective_at', '<=', $effective)
                ->select('id', 'signed_amount as total', 'effective_at')->selectRaw("'opening' AS source");
            $purchases = DB::table('purchases')->where($this->scope($account) + ['status' => 'posted'])
                ->where('received_at', '<=', $effective)->select('id', 'total', 'received_at as effective_at')->selectRaw("'purchase' AS source");
            $sales = DB::table('sales')->where($this->scope($account) + ['status' => 'completed'])
                ->where('issued_at', '<=', $effective)->select('id', 'total', 'issued_at as effective_at')->selectRaw("'sale' AS source");
            $targets = DB::query()->fromSub($opening->unionAll($account->customer_id ? $sales : $purchases), 'dues')
                ->orderBy('effective_at')->orderBy('id')->orderBy('source')->get();
            foreach ($targets as $target) {
                $purchase = $target->source === 'purchase';
                $sale = $target->source === 'sale';
                $column = $sale ? 'sale_id' : 'party_opening_entry_id';
                $available = $purchase ? $this->purchaseCapacity($target->id, $account->supplier_id, $effective)
                    : BigDecimal::of($target->total)->minus($this->peakAllocated($column, $target->id, $effective, (bool) $account->customer_id));
                if ($sale) {
                    $available = BigDecimal::min($available, BigDecimal::of(DB::table('sales')->where('id', $target->id)->value('balance_amount')));
                }
                $allocate = BigDecimal::min($amount, $available);
                if ($allocate->isPositive()) {
                    $this->allocation($request, $id, $purchase || $sale ? null : $target->id, $allocate, $effective,
                        purchase: $purchase ? $target->id : null, sale: $sale ? $target->id : null);
                    $amount = $amount->minus($allocate);
                }
                if ($amount->isZero()) {
                    break;
                }
            }
        }
        app(PostingAudit::class)->record($request, 'payment.created', 'payment', $id, null, $row, $data['reason'] ?? null);

        return (object) ($row + ['id' => $id]);
    }

    public function previewPayment(Request $request, ?object $account, array $data): array
    {
        [$amount, , $effective, $evidence] = $this->paymentIntent($request, $account, $data);

        return [
            'paid_at' => $effective, 'balance_effect' => $evidence === null ? 'normal' : 'included_in_opening',
            'balance_delta' => $evidence === null ? (string) $amount->negated() : '0.00',
        ];
    }

    private function paymentIntent(Request $request, ?object $account, array $data): array
    {
        $amount = $this->positive($data['amount']);
        $historical = (bool) ($data['historical_entry'] ?? false);
        if ($historical || isset($data['included_in_opening_entry_id']) || isset($data['paid_at'])) {
            abort_unless($request->user()->hasRole('Owner'), 403);
        }
        if ($historical && ! isset($data['paid_at'], $data['reason'])) {
            throw ValidationException::withMessages(['paid_at' => 'Historical payments require an effective date and reason.']);
        }
        if (isset($data['paid_at']) && ! $historical) {
            throw ValidationException::withMessages(['paid_at' => 'Use the historical entry option for an explicit payment date.']);
        }
        $effective = isset($data['paid_at']) ? $this->date($data['paid_at']) : CarbonImmutable::now();
        $evidence = $data['included_in_opening_entry_id'] ?? null;
        if ($evidence !== null) {
            abort_unless($historical, 422, 'Included-in-opening evidence requires a historical entry.');
            $target = DB::table('party_opening_entries')->where(['id' => $evidence, 'party_account_id' => $account?->id, 'status' => 'posted'])
                ->whereNull('reversal_of_id')->first();
            abort_unless($target, 404);
            abort_if($effective->isAfter(CarbonImmutable::parse($target->effective_at, 'UTC')), 422, 'Evidence cannot be later than the opening entry.');
        }

        return [$amount, $historical, $effective, $evidence];
    }

    public function apply(Request $request, object $account, int $id, array $data, string $kind = 'explicit'): int
    {
        $payment = $this->lockedPayment($account, $id);
        abort_unless($payment->status === 'posted' && $payment->direction === ($account->customer_id ? 'in' : 'out') && $payment->balance_effect === 'normal', 409);
        $purchase = isset($data['purchase_id']);
        $sale = isset($data['sale_id']);
        $target = $sale
            ? DB::table('sales')->where($this->scope($account) + ['id' => $data['sale_id'], 'status' => 'completed'])->lockForUpdate()->first()
            : ($purchase
            ? DB::table('purchases')->where($this->scope($account) + ['id' => $data['purchase_id'], 'status' => 'posted'])->lockForUpdate()->first()
            : DB::table('party_opening_entries')->where([
                'id' => $data['opening_entry_id'], 'party_account_id' => $account->id, 'entry_type' => 'due', 'status' => 'posted',
            ])->lockForUpdate()->first());
        abort_unless($target, 404);
        $effective = $this->date($data['effective_at']);
        $this->assertDateOrder($effective, $payment->paid_at);
        $this->assertDateOrder($effective, $sale ? $target->issued_at : ($purchase ? $target->received_at : $target->effective_at));
        $amount = $this->positive($data['amount']);
        $used = $this->peakAllocated('payment_id', $id, $effective);
        $due = match (true) {
            $sale => BigDecimal::min(BigDecimal::of($target->balance_amount), BigDecimal::of($target->total)->minus($this->peakAllocated('sale_id', $target->id, $effective, true))),
            $purchase => $this->purchaseCapacity($target->id, $account->supplier_id, $effective),
            default => $this->openDue($target, $effective, (bool) $account->customer_id),
        };
        abort_if($amount->isGreaterThan(BigDecimal::of($payment->amount)->minus($used)) || $amount->isGreaterThan($due),
            422, 'The amount exceeds the remaining advance or open due.');

        $allocation = $this->allocation($request, $id, $purchase || $sale ? null : $target->id, $amount, $effective,
            kind: $kind, purchase: $purchase ? $target->id : null, sale: $sale ? $target->id : null);
        app(PostingAudit::class)->record($request, 'payment.applied', 'payment_allocation', $allocation, null, $data);

        return $allocation;
    }

    public function applyOpeningCredit(Request $request, object $account, int $source, array $data): int
    {
        $entries = DB::table('party_opening_entries')->where(['party_account_id' => $account->id, 'status' => 'posted']);
        $credit = (clone $entries)->where('id', $source)->whereIn('entry_type', ['credit', 'advance'])->lockForUpdate()->first();
        $sale = isset($data['sale_id']);
        $due = $sale
            ? DB::table('sales')->where($this->scope($account) + ['id' => $data['sale_id'], 'status' => 'completed'])->lockForUpdate()->first()
            : $entries->where(['id' => $data['opening_entry_id'], 'entry_type' => 'due'])->lockForUpdate()->first();
        abort_unless($credit && $due, 404);
        $effective = $this->date($data['effective_at']);
        $this->assertDateOrder($effective, $credit->effective_at);
        $this->assertDateOrder($effective, $sale ? $due->issued_at : $due->effective_at);
        $amount = $this->positive($data['amount']);
        $used = $this->peakAllocated('party_opening_entry_id', $source, $effective, creditSource: true);
        $capacity = $sale ? BigDecimal::min(BigDecimal::of($due->balance_amount), BigDecimal::of($due->total)->minus($this->peakAllocated('sale_id', $due->id, $effective, true)))
            : $this->openDue($due, $effective, true);
        abort_if($amount->isGreaterThan(BigDecimal::of($credit->signed_amount)->negated()->minus($used))
            || $amount->isGreaterThan($capacity), 422, 'The amount exceeds remaining opening credit or open due.');
        $row = [
            'party_opening_entry_id' => $source, 'opening_due_id' => $sale ? null : $due->id, 'sale_id' => $sale ? $due->id : null,
            'amount' => (string) $amount,
            'effective_at' => $effective, 'created_at' => now(), 'created_by' => $request->user()->id,
        ];
        $id = DB::table('party_credit_allocations')->insertGetId($row);
        if ($sale) {
            DB::table('sales')->where('id', $due->id)->decrement('balance_amount', (string) $amount);
        }
        app(PostingAudit::class)->record($request, 'opening.credit_applied', 'party_credit_allocation', $id, null, $row);

        return $id;
    }

    public function reverseOpeningCredit(Request $request, object $account, int $source, int $id, string $reason): int
    {
        $original = DB::table('party_credit_allocations')->where(['id' => $id, 'party_opening_entry_id' => $source])
            ->whereIn('party_opening_entry_id', DB::table('party_opening_entries')->where('party_account_id', $account->id)->select('id'))
            ->lockForUpdate()->first();
        abort_unless($original, 404);
        abort_if($original->reversal_of_id || DB::table('party_credit_allocations')->where('reversal_of_id', $id)->exists(), 409, 'Credit application is already reversed or is a reversal.');
        abort_if($original->sale_id && DB::table('sale_returns')->where(['sale_id' => $original->sale_id, 'status' => 'issued'])->exists(),
            409, 'This opening credit application has an issued credit note dependency.');
        $row = [
            'party_opening_entry_id' => $source, 'opening_due_id' => $original->opening_due_id, 'sale_id' => $original->sale_id,
            'amount' => (string) BigDecimal::of($original->amount)->negated(),
            'effective_at' => now(), 'created_at' => now(), 'created_by' => $request->user()->id, 'reversal_of_id' => $id,
        ];
        $reversal = DB::table('party_credit_allocations')->insertGetId($row);
        if ($original->sale_id !== null) {
            DB::table('sales')->where('id', $original->sale_id)->increment('balance_amount', $original->amount);
        }
        app(PostingAudit::class)->record($request, 'opening.credit_unapplied', 'party_credit_allocation', $reversal, (array) $original, $row, $reason);

        return $reversal;
    }

    public function reversePayment(Request $request, object $account, int $id, string $reason): object
    {
        $original = $this->lockedPayment($account, $id);
        if ($account->customer_id && $original->historical_entry) {
            abort_unless($request->user()->hasRole('Owner'), 403);
        }
        abort_if($original->status === 'reversed' || $original->reversal_of_id !== null, 409, 'Payment already reversed or is a reversal.');
        $cancelledSales = DB::table('audit_logs')->where(['tenant_id' => $account->tenant_id, 'action' => 'sale.cancelled'])
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(after_json, '$.refund_payment_id')) = 'INTEGER'");
        $salePostingRequests = DB::table('audit_logs')->where(['tenant_id' => $account->tenant_id, 'action' => 'payment.created', 'entity_id' => $id])
            ->select('request_id');
        $originatingSales = DB::table('audit_logs')->where(['tenant_id' => $account->tenant_id, 'action' => 'sale.completed'])
            ->whereIn('request_id', $salePostingRequests)->select('entity_id');
        abort_if($cancelledSales->where(function ($query) use ($id, $originatingSales) {
            $query->where('after_json->refund_payment_id', $id)->orWhereIn('entity_id', $originatingSales);
        })->exists(), 409, 'This payment belongs to a refunded sale cancellation.');
        $allocations = $this->activeAllocations($id)->get();
        abort_if(DB::table('sale_returns')->where('status', 'issued')->where(function ($query) use ($id, $allocations) {
            $query->where('refund_payment_id', $id)->orWhereIn('sale_id', $allocations->pluck('sale_id')->filter()->all());
        })->exists(), 409, 'This payment has an issued credit note dependency.');
        abort_if($allocations->contains(fn ($row) => $row->application_kind === 'explicit'), 409,
            'Unapply later advance allocations before reversing this payment.');
        $now = CarbonImmutable::now();
        $row = $this->scope($account) + [
            'direction' => $original->direction === 'in' ? 'out' : 'in', 'purpose' => 'reversal', 'mode' => $original->mode, 'amount' => $original->amount,
            'paid_at' => $now, 'created_at' => $now, 'created_by' => $request->user()->id, 'reversal_of_id' => $id,
            'included_in_opening_entry_id' => $original->included_in_opening_entry_id,
            'balance_effect' => $original->balance_effect, 'reason' => $reason,
        ];
        $reversalId = DB::table('payments')->insertGetId($row);
        foreach ($allocations as $allocation) {
            $this->allocation($request, $reversalId, $allocation->party_opening_entry_id,
                BigDecimal::of($allocation->amount)->negated(), $now, $allocation->id, purchase: $allocation->purchase_id, sale: $allocation->sale_id);
        }
        DB::table('payments')->where('id', $id)->update(['status' => 'reversed']);
        app(PostingAudit::class)->record($request, 'payment.reversed', 'payment', $reversalId, (array) $original, $row, $reason);

        return (object) ($row + ['id' => $reversalId]);
    }

    public function unapply(Request $request, object $account, int $payment, int $allocation, string $reason): int
    {
        $record = $this->lockedPayment($account, $payment);
        abort_unless($record->status === 'posted' && $record->direction === ($account->customer_id ? 'in' : 'out'), 409);
        $original = $this->activeAllocations($payment)->where('allocation.id', $allocation)->first();
        abort_unless($original, 404);
        abort_if($original->sale_id && DB::table('sale_returns')->where(['sale_id' => $original->sale_id, 'status' => 'issued'])->exists(),
            409, 'This allocation has an issued credit note dependency.');
        $id = $this->allocation($request, $payment, $original->party_opening_entry_id,
            BigDecimal::of($original->amount)->negated(), CarbonImmutable::now(), $allocation, purchase: $original->purchase_id, sale: $original->sale_id);
        app(PostingAudit::class)->record($request, 'payment.unapplied', 'payment_allocation', $id, (array) $original, null, $reason);

        return $id;
    }

    public function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value, 'Asia/Kolkata')->utc();
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['effective_at' => 'Financial entries cannot be dated in the future.']);
        }

        return $date;
    }

    private function assertDateOrder(CarbonImmutable $effective, string $earliest): void
    {
        abort_if($effective->isBefore(CarbonImmutable::parse($earliest, 'UTC')), 422, 'The effective date precedes the source entry.');
    }

    private function scope(object $account): array
    {
        return ['tenant_id' => $account->tenant_id, 'branch_id' => $account->branch_id]
            + ($account->customer_id ? ['customer_id' => $account->customer_id] : ['supplier_id' => $account->supplier_id]);
    }

    private function lockedPayment(object $account, int $id): object
    {
        $payment = DB::table('payments')->where($this->scope($account) + ['id' => $id])->lockForUpdate()->first();
        abort_unless($payment, 404);

        return $payment;
    }

    private function activeAllocations(int $payment): Builder
    {
        return DB::table('payment_allocations as allocation')->where('allocation.payment_id', $payment)
            ->where('allocation.amount', '>', 0)->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('payment_allocations as reversal')->whereColumn('reversal.reversal_of_id', 'allocation.id'));
    }

    private function positive(string $value): BigDecimal
    {
        $amount = BigDecimal::of(Money::amount($value));
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
        }

        return $amount;
    }

    private function openDue(object $target, CarbonImmutable $effective, bool $customer = false): BigDecimal
    {
        return BigDecimal::of($target->signed_amount)->minus($this->peakAllocated('party_opening_entry_id', $target->id, $effective, $customer));
    }

    private function peakAllocated(string $column, int $id, CarbonImmutable $effective, bool $includeCredit = false, bool $creditSource = false): BigDecimal
    {
        $cutoff = $effective->toDateTimeString();
        $running = BigDecimal::zero();
        $peak = null;
        $rows = DB::table($creditSource ? 'party_credit_allocations' : 'payment_allocations')->where($column, $id)->select('effective_at', 'amount');
        if ($includeCredit) {
            $rows->unionAll(DB::table('party_credit_allocations')->where($column === 'sale_id' ? 'sale_id' : 'opening_due_id', $id)->select('effective_at', 'amount'));
        }
        $events = DB::query()->fromSub($rows, 'allocations')->select('effective_at')
            ->selectRaw('SUM(amount) AS amount_delta')->groupBy('effective_at')->orderBy('effective_at')->get();
        foreach ($events as $event) {
            if ($event->effective_at > $cutoff && $peak === null) {
                $peak = $running;
            }
            $running = $running->plus($event->amount_delta);
            if ($event->effective_at >= $cutoff) {
                $peak = BigDecimal::max($peak ?? $running, $running);
            }
        }

        return $peak ?? $running;
    }

    private function purchaseCapacity(int $purchase, int $supplier, CarbonImmutable $effective): BigDecimal
    {
        $events = [];
        foreach (DB::table('purchase_revisions')->where('purchase_id', $purchase)->orderBy('revision_no')->get() as $revision) {
            $snapshot = json_decode($revision->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $events[$revision->financial_effective_at]['total'] = $revision->action !== 'soft_delete' && $revision->resulting_supplier_id === $supplier
                ? $snapshot['total'] : '0.00';
        }
        foreach (DB::table('payment_allocations')->where('purchase_id', $purchase)->select('effective_at')
            ->selectRaw('SUM(amount) AS amount')->groupBy('effective_at')->get() as $allocation) {
            $events[$allocation->effective_at]['allocation'] = $allocation->amount;
        }
        $cutoff = $effective->toDateTimeString();
        $events[$cutoff] ??= [];
        ksort($events, SORT_STRING);
        $total = BigDecimal::zero();
        $allocated = BigDecimal::zero();
        $capacity = null;
        foreach ($events as $date => $event) {
            if (isset($event['total'])) {
                $total = BigDecimal::of($event['total']);
            }
            $allocated = $allocated->plus($event['allocation'] ?? '0.00');
            if ($date >= $cutoff) {
                $available = BigDecimal::max(BigDecimal::zero(), $total->minus($allocated));
                $capacity = $capacity === null ? $available : BigDecimal::min($capacity, $available);
            }
        }

        return $capacity ?? BigDecimal::zero();
    }

    private function allocation(Request $request, int $payment, ?int $target, BigDecimal $amount, CarbonImmutable $effective, ?int $reversal = null, string $kind = 'automatic', ?int $purchase = null, ?int $sale = null): int
    {
        $id = DB::table('payment_allocations')->insertGetId([
            'payment_id' => $payment, 'party_opening_entry_id' => $target, 'purchase_id' => $purchase, 'sale_id' => $sale, 'amount' => (string) $amount->toScale(2),
            'effective_at' => $effective, 'created_at' => now(), 'created_by' => $request->user()->id,
            'reversal_of_id' => $reversal, 'application_kind' => $kind,
        ]);
        if ($purchase !== null) {
            DB::table('purchases')->where('id', $purchase)->update([
                'paid_amount' => DB::raw('paid_amount + '.(string) $amount->toScale(2)),
                'balance_amount' => DB::raw('balance_amount - ('.(string) $amount->toScale(2).')'),
            ]);
        }
        if ($sale !== null) {
            DB::table('sales')->where('id', $sale)->update([
                'paid_amount' => DB::raw('paid_amount + '.(string) $amount->toScale(2)),
                'balance_amount' => DB::raw('balance_amount - ('.(string) $amount->toScale(2).')'),
            ]);
        }

        return $id;
    }
}
