<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Customer;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\SupplierAccountPosting;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('customers.view'), 403);
        $data = $request->validate(['search' => 'sometimes|required|string|max:100']);
        $query = Customer::where('tenant_id', $request->user()->tenant_id);
        if (! $request->user()->can('customers.manage')) {
            $query->where('is_active', true);
        }
        if (isset($data['search'])) {
            $prefix = addcslashes($data['search'], '\\%_').'%';
            $query->where(fn ($builder) => $builder->whereLike('name', $prefix)->orWhereLike('phone', $prefix));
        }

        return ApiResponse::page($request, $query, ['name']);
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        abort_unless($request->user()->can('customers.view'), 403);
        $record = $this->customer($request, $customer);
        abort_unless($record->is_active || $request->user()->can('customers.manage'), 404);

        return ApiResponse::data($record);
    }

    public function save(Request $request, ?int $customer = null): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can($customer === null ? 'customers.create' : 'customers.manage'), 403);
        $presence = $customer === null ? 'required' : 'sometimes';
        $restricted = $actor->can('customers.manage') ? 'sometimes' : 'prohibited';
        $data = $request->validate([
            'name' => [$presence, 'required', 'string', 'max:255'],
            'phone' => 'sometimes|nullable|string|max:32', 'address' => 'sometimes|nullable|string|max:2000',
            'state_code' => 'sometimes|nullable|string|exists:gst_states,code',
            'gstin' => ['sometimes', 'nullable', 'regex:/\A[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]\z/'],
            'credit_limit' => $restricted.'|string',
            'preferred_sale_type' => $restricted.'|in:retail,wholesale',
            'is_active' => $restricted.'|boolean', 'is_system' => 'prohibited',
        ]);
        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/[\s().-]+/', '', $data['phone']);
            if (! preg_match('/\A\+?[0-9]{3,20}\z/', $data['phone'])) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
            }
        }
        if (isset($data['credit_limit'])) {
            $data['credit_limit'] = Money::amount($data['credit_limit']);
        }

        return DB::transaction(function () use ($request, $actor, $customer, $data) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $record = $customer === null ? new Customer : Customer::where('tenant_id', $actor->tenant_id)->lockForUpdate()->findOrFail($customer);
            abort_if($record->is_system, 422, 'Walk-in cannot be edited or deactivated.');
            $before = $record->exists ? $record->toArray() : null;
            $record->forceFill($data + ['tenant_id' => $actor->tenant_id]);
            if ($record->gstin && substr($record->gstin, 0, 2) !== $record->state_code) {
                throw ValidationException::withMessages(['gstin' => 'GSTIN must match the customer state.']);
            }
            if (($record->is_active ?? true) && $record->phone && Customer::where('tenant_id', $actor->tenant_id)
                ->where('active_phone', $record->phone)->when($customer, fn ($query) => $query->where('id', '!=', $customer))->exists()) {
                throw ValidationException::withMessages(['phone' => 'This phone is already used by an active customer.']);
            }
            $record->save();
            $record->refresh();
            app(PostingAudit::class)->record($request, $before ? 'customer.updated' : 'customer.created', 'customer', $record->id, $before, $record->toArray());

            return ApiResponse::data($record, $before ? 200 : 201);
        }, 3);
    }

    public function post(Request $request, int $customer, SupplierAccountPosting $posting, PostingTransaction $transaction): JsonResponse
    {
        $action = $request->route('operation');
        $receipt = in_array($action, ['receipt', 'receipt-preview'], true);
        $actor = $request->user();
        $allowed = match ($action) {
            'receipt', 'receipt-preview', 'receipt-apply' => $actor->can('payments.receive'),
            'receipt-reverse', 'receipt-unapply' => $actor->can('payments.reverse'),
            default => $actor->hasRole('Owner') && $actor->can('opening.manage'),
        };
        abort_unless($allowed, 403);
        $record = $this->customer($request, $customer);
        abort_if($record->is_system, 422, 'Walk-in has no credit account.');
        $reason = ['reason' => 'required|string|max:255'];
        $date = ['effective_at' => 'required|date'];
        $data = $request->validate(match ($action) {
            'opening' => $reason + $date + [
                'amount' => 'required|string', 'entry_type' => 'required|in:due,credit,advance',
                'replaces_entry_id' => 'sometimes|integer|min:1', 'reference' => 'sometimes|nullable|string|max:255',
                'note' => 'sometimes|nullable|string|max:2000',
            ],
            'zero', 'reverse-opening' => $reason + $date,
            'receipt-reverse', 'receipt-unapply', 'credit-unapply' => $reason,
            'receipt-apply', 'credit-apply' => [
                'effective_at' => 'sometimes|required|date', 'amount' => 'required|string',
                'opening_entry_id' => 'required_without:sale_id|prohibits:sale_id|integer|min:1',
                'sale_id' => 'required_without:opening_entry_id|prohibits:opening_entry_id|integer|min:1',
            ],
            'receipt', 'receipt-preview' => [
                'amount' => 'required|string', 'mode' => 'required|in:cash,upi,card,bank',
                'reference' => 'sometimes|nullable|string|max:255', 'reason' => 'sometimes|required|string|max:255',
                'historical_entry' => 'sometimes|boolean', 'paid_at' => 'sometimes|required|date',
                'included_in_opening_entry_id' => 'sometimes|integer|min:1', 'auto_allocate' => 'sometimes|boolean',
                'confirm_balance_effect' => 'sometimes|boolean',
                'allocations' => $action === 'receipt' ? 'sometimes|required|array|min:1|max:50' : 'prohibited',
                'allocations.*' => 'array:opening_entry_id,sale_id,amount',
                'allocations.*.opening_entry_id' => 'required_without:allocations.*.sale_id|prohibits:allocations.*.sale_id|integer|min:1|distinct',
                'allocations.*.sale_id' => 'required_without:allocations.*.opening_entry_id|prohibits:allocations.*.opening_entry_id|integer|min:1|distinct',
                'allocations.*.amount' => 'required|string',
            ],
        });
        $branch = $request->attributes->get('branch');
        if ($action === 'receipt-preview') {
            $account = DB::table('party_accounts')->where(['branch_id' => $branch->id, 'customer_id' => $customer])->first();

            return ApiResponse::data($posting->previewPayment($request, $account, $data));
        }
        if ($receipt && ($data['historical_entry'] ?? false) && ! isset($data['included_in_opening_entry_id']) && ! ($data['confirm_balance_effect'] ?? false)) {
            throw ValidationException::withMessages(['confirm_balance_effect' => 'Review the receipt preview and confirm the additional balance effect.']);
        }
        $entry = (int) $request->route('entry');
        $allocation = (int) $request->route('allocation');
        if (in_array($action, ['receipt-apply', 'credit-apply'], true) && isset($data['effective_at'])) {
            abort_unless($actor->hasRole('Owner'), 403);
        }

        return $transaction->run($request, 'customer.'.$action, ['customer_id' => $customer, 'entry' => $entry, 'allocation' => $allocation, 'data' => $data], function () use ($request, $customer, $posting, $action, $entry, $allocation, $data) {
            $account = $posting->account($request, $customer, customer: true);
            $intent = $data + ['effective_at' => now()->toIso8601String()];
            $result = match ($action) {
                'opening' => $posting->opening($request, $account, $data),
                'zero' => $posting->status($request, $account, 'confirmed_zero', $posting->date($data['effective_at']), $data['reason']),
                'reverse-opening' => $posting->reverseOpening($request, $account, $entry, $data),
                'receipt' => $this->receipt($request, $posting, $account, $data),
                'receipt-apply' => ['allocation_id' => $posting->apply($request, $account, $entry, $intent)],
                'receipt-reverse' => $posting->reversePayment($request, $account, $entry, $data['reason']),
                'receipt-unapply' => ['allocation_id' => $posting->unapply($request, $account, $entry, $allocation, $data['reason'])],
                'credit-apply' => ['allocation_id' => $posting->applyOpeningCredit($request, $account, $entry, $intent)],
                'credit-unapply' => ['allocation_id' => $posting->reverseOpeningCredit($request, $account, $entry, $allocation, $data['reason'])],
            };

            return ApiResponse::data($result, 201);
        });
    }

    private function receipt(Request $request, SupplierAccountPosting $posting, object $account, array $data): object
    {
        if (isset($data['allocations'])) {
            if (isset($data['included_in_opening_entry_id']) || ($data['auto_allocate'] ?? false)) {
                throw ValidationException::withMessages(['allocations' => 'Choose either explicit allocations, automatic allocation, or opening evidence.']);
            }
            $data['auto_allocate'] = false;
        }
        $receipt = $posting->payment($request, $account, $data);
        foreach ($data['allocations'] ?? [] as $allocation) {
            $posting->apply($request, $account, $receipt->id, $allocation + ['effective_at' => $receipt->paid_at->toIso8601String()], 'automatic');
        }
        $receipt->allocations = DB::table('payment_allocations')->where('payment_id', $receipt->id)->orderBy('id')->get();

        return $receipt;
    }

    public function receiptAllocations(Request $request, int $customer, int $entry): JsonResponse
    {
        abort_unless($request->user()->can('customers.view'), 403);
        $record = $this->customer($request, $customer);
        $branch = $request->attributes->get('branch');
        $receipt = DB::table('payments')->where([
            'id' => $entry, 'tenant_id' => $record->tenant_id, 'branch_id' => $branch->id, 'customer_id' => $record->id,
        ])->first();
        abort_unless($receipt, 404);
        $entries = DB::table('payment_allocations')->where('payment_id', $entry)->orderBy('id')->get();

        return ApiResponse::data($entries);
    }

    public function ledger(Request $request, int $customer, SupplierAccountPosting $posting): JsonResponse
    {
        abort_unless($request->user()->can('customers.view'), 403);
        $record = $this->customer($request, $customer);
        abort_if($record->is_system, 422, 'Walk-in has no credit account.');
        $branch = $request->attributes->get('branch');
        $filters = $request->validate([
            'as_of' => 'sometimes|required|date', 'recorded_as_of' => 'sometimes|required|date',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100',
        ]);

        return DB::transaction(function () use ($record, $branch, $filters, $posting) {
            $scope = ['tenant_id' => $record->tenant_id, 'customer_id' => $record->id, 'branch_id' => $branch->id];
            $account = DB::table('party_accounts')->where($scope)->first();
            $opening = DB::table('party_opening_entries')->where('party_account_id', $account?->id)
                ->select('id', 'effective_at', 'created_at', 'reference', 'signed_amount')
                ->selectRaw("'opening' AS source, entry_type AS kind");
            $receipts = DB::table('payments')->where($scope)
                ->select('id', 'paid_at as effective_at', 'created_at', 'reference')
                ->selectRaw("CASE WHEN balance_effect = 'included_in_opening' THEN 0.00 WHEN direction = 'in' THEN -amount ELSE amount END AS signed_amount")
                ->selectRaw("'receipt' AS source, purpose AS kind");
            $sales = DB::table('sales')->where($scope)->whereNotNull('issued_document_id')
                ->select('id', 'issued_at as effective_at', 'issued_at as created_at', 'invoice_no as reference', 'total as signed_amount')
                ->selectRaw("'sale' AS source, document_type AS kind");
            $scopedSales = DB::table('sales')->where($scope)->select('id');
            $returns = DB::table('sale_returns')->whereIn('sale_id', clone $scopedSales)
                ->select('id', 'issued_at as effective_at', 'issued_at as created_at', 'credit_note_no as reference')
                ->selectRaw("-total AS signed_amount, 'sale_return' AS source, 'credit_note' AS kind");
            $cancelledSales = DB::table('sales')->join('issued_documents as registry', 'registry.id', '=', 'sales.issued_document_id')
                ->whereIn('sales.id', clone $scopedSales)->whereNotNull('registry.cancelled_at')
                ->select('sales.id', 'registry.cancelled_at as effective_at', 'registry.cancelled_at as created_at', 'registry.document_no as reference')
                ->selectRaw("-sales.total AS signed_amount, 'sale_cancel' AS source, 'cancellation' AS kind");
            $cancelledReturns = DB::table('sale_returns')->join('issued_documents as registry', 'registry.id', '=', 'sale_returns.issued_document_id')
                ->whereIn('sale_returns.sale_id', $scopedSales)->whereNotNull('registry.cancelled_at')
                ->select('sale_returns.id', 'registry.cancelled_at as effective_at', 'registry.cancelled_at as created_at', 'registry.document_no as reference')
                ->selectRaw("sale_returns.total AS signed_amount, 'sale_return_cancel' AS source, 'cancellation' AS kind");
            $entries = DB::query()->fromSub($opening->unionAll($receipts)->unionAll($sales)->unionAll($returns)
                ->unionAll($cancelledSales)->unionAll($cancelledReturns), 'events');
            foreach (['as_of' => 'effective_at', 'recorded_as_of' => 'created_at'] as $filter => $column) {
                if (isset($filters[$filter])) {
                    $entries->where($column, '<=', $posting->date($filters[$filter]));
                }
            }
            $balance = (string) BigDecimal::of((clone $entries)->sum('signed_amount'))->toScale(2);
            $page = $entries->select('events.*')->selectRaw('SUM(signed_amount) OVER (ORDER BY effective_at, created_at, source, id) AS running_balance')
                ->orderByDesc('effective_at')->orderByDesc('created_at')->orderByDesc('source')->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 25);
            $status = $account->opening_status ?? 'not_entered';
            if ($account && ((isset($filters['as_of']) && $account->opening_status_effective_at > $posting->date($filters['as_of'])->toDateTimeString())
                || (isset($filters['recorded_as_of']) && $account->opening_confirmed_at > $posting->date($filters['recorded_as_of'])->toDateTimeString()))) {
                $status = 'not_entered';
            }

            return ApiResponse::data($page->items(), meta: [
                'account_id' => $account?->id, 'opening_status' => $status, 'balance_incomplete' => $status === 'not_entered',
                'opening_status_effective_at' => $status !== 'not_entered' ? $account?->opening_status_effective_at : null,
                'opening_confirmed_at' => $status !== 'not_entered' ? $account?->opening_confirmed_at : null,
                'balance' => $balance, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            ]);
        });
    }

    public function balances(Request $request, int $customer): JsonResponse
    {
        abort_unless($request->user()->can('customers.manage'), 403);
        $record = $this->customer($request, $customer);
        abort_if($record->is_system, 422, 'Walk-in has no credit account.');
        $device = $request->attributes->get('device');
        $branches = $request->user()->accessibleBranches()->get();

        return DB::transaction(function () use ($record, $branches, $device) {
            $result = [];
            $net = BigDecimal::zero();
            $outstanding = BigDecimal::zero();
            foreach ($branches as $branch) {
                if ($device && ! $device->branches()->whereKey($branch->id)->exists()) {
                    continue;
                }
                $scope = ['tenant_id' => $record->tenant_id, 'customer_id' => $record->id, 'branch_id' => $branch->id];
                $account = DB::table('party_accounts')->where($scope)->first();
                $opening = BigDecimal::of(DB::table('party_opening_entries')->where('party_account_id', $account?->id)->sum('signed_amount'));
                $receipts = DB::table('payments')->where($scope + ['balance_effect' => 'normal'])
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS total")->value('total');
                $sales = DB::table('sales')->where($scope + ['status' => 'completed'])->sum('total');
                $returns = DB::table('sale_returns')->whereIn('sale_id', DB::table('sales')->where($scope)->select('id'))
                    ->where('status', 'issued')->sum('total');
                $balance = $opening->plus($sales)->minus($receipts)->minus($returns);
                $net = $net->plus($balance);
                $outstanding = $outstanding->plus(BigDecimal::max(BigDecimal::zero(), $balance));
                $result[] = ['branch_id' => $branch->id, 'opening_status' => $account->opening_status ?? 'not_entered',
                    'opening_balance' => (string) $opening->toScale(2), 'balance' => (string) $balance->toScale(2)];
            }

            $completeScope = count($result) === DB::table('branches')->where('tenant_id', $record->tenant_id)->where('is_active', true)->count();

            return ApiResponse::data($result, meta: [
                'credit_limit' => $record->credit_limit, 'net_balance' => (string) $net->toScale(2),
                'outstanding' => (string) $outstanding->toScale(2), 'all_active_branches_included' => $completeScope,
                'balance_incomplete' => ! $completeScope || in_array('not_entered', array_column($result, 'opening_status'), true),
            ]);
        });
    }

    private function customer(Request $request, int $customer): Customer
    {
        return Customer::where('tenant_id', $request->user()->tenant_id)->findOrFail($customer);
    }
}
