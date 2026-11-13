<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Supplier;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\SupplierAccountPosting;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('suppliers.manage'), 403);
        $data = $request->validate(['search' => 'sometimes|required|string|max:100']);
        $query = Supplier::where('tenant_id', $request->user()->tenant_id);
        if (isset($data['search'])) {
            $prefix = addcslashes($data['search'], '\\%_').'%';
            $query->where(fn ($builder) => $builder->whereLike('name', $prefix)->orWhereLike('phone', $prefix));
        }

        return ApiResponse::page($request, $query, ['name']);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $supplier): JsonResponse
    {
        return $this->save($request, $supplier);
    }

    private function save(Request $request, ?int $supplier): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('suppliers.manage'), 403);
        $presence = $supplier === null ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$presence, 'required', 'string', 'max:255'],
            'phone' => 'sometimes|nullable|string|max:32',
            'address' => 'sometimes|nullable|string|max:2000',
            'state_code' => 'sometimes|nullable|string|exists:gst_states,code',
            'gstin' => ['sometimes', 'nullable', 'regex:/\A[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]\z/'],
            'is_active' => 'sometimes|boolean',
        ]);

        return DB::transaction(function () use ($request, $user, $supplier, $data) {
            $record = $supplier === null ? new Supplier : Supplier::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($supplier);
            $before = $record->exists ? $record->toArray() : null;
            $record->forceFill($data + ['tenant_id' => $user->tenant_id]);
            if ($record->gstin && substr($record->gstin, 0, 2) !== $record->state_code) {
                throw ValidationException::withMessages(['gstin' => 'GSTIN must match the supplier state.']);
            }
            $record->save();
            app(PostingAudit::class)->record($request, $before === null ? 'supplier.created' : 'supplier.updated',
                'supplier', $record->id, $before, $record->toArray());

            return ApiResponse::data($record->refresh(), $supplier === null ? 201 : 200);
        }, 3);
    }

    public function ledger(Request $request, int $supplier, SupplierAccountPosting $posting): JsonResponse
    {
        abort_unless($request->user()->can('suppliers.manage'), 403);
        $branch = $request->attributes->get('branch');
        Supplier::where('tenant_id', $branch->tenant_id)->findOrFail($supplier);
        $filters = $request->validate([
            'as_of' => 'sometimes|required|date', 'recorded_as_of' => 'sometimes|required|date',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100',
        ]);

        return DB::transaction(function () use ($branch, $supplier, $filters, $posting) {
            $scope = ['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'supplier_id' => $supplier];
            $account = DB::table('party_accounts')->where($scope)->first();
            $opening = DB::table('party_opening_entries')->where('party_account_id', $account?->id)
                ->select('id', 'effective_at', 'created_at', 'reference', 'signed_amount')
                ->selectRaw("'opening' AS source, entry_type AS kind");
            $payments = DB::table('payments')->where($scope)
                ->select('id', 'paid_at as effective_at', 'created_at', 'reference')
                ->selectRaw("CASE WHEN balance_effect = 'included_in_opening' THEN 0.00 WHEN direction = 'out' THEN -amount ELSE amount END AS signed_amount")
                ->selectRaw("'payment' AS source, purpose AS kind");
            $versions = DB::table('purchase_revisions')->select('purchase_id')->selectRaw('MAX(revision_no) AS revision_no')
                ->where('tenant_id', $branch->tenant_id)->groupBy('purchase_id');
            if (isset($filters['recorded_as_of'])) {
                $versions->where('correction_posted_at', '<=', $posting->date($filters['recorded_as_of']));
            }
            if (isset($filters['as_of'])) {
                $versions->where('financial_effective_at', '<=', $posting->date($filters['as_of']));
            }
            $purchases = DB::table('purchase_revisions as revision')->joinSub($versions, 'latest', fn ($join) => $join
                ->on('revision.purchase_id', '=', 'latest.purchase_id')->on('revision.revision_no', '=', 'latest.revision_no'))
                ->join('purchases', 'purchases.id', '=', 'revision.purchase_id')
                ->where(['purchases.tenant_id' => $branch->tenant_id, 'purchases.branch_id' => $branch->id, 'revision.resulting_supplier_id' => $supplier])
                ->where('revision.action', '!=', 'soft_delete')
                ->select('purchases.id', 'purchases.received_at as effective_at', 'revision.correction_posted_at as created_at')
                ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(revision.after_snapshot, '$.supplier_invoice_no')) AS reference, CAST(JSON_UNQUOTE(JSON_EXTRACT(revision.after_snapshot, '$.total')) AS DECIMAL(18,2)) AS signed_amount")
                ->selectRaw("'purchase' AS source, 'purchase' AS kind");
            $returns = DB::table('purchase_returns as returned')->join('purchases', 'purchases.id', '=', 'returned.purchase_id')
                ->where(['purchases.tenant_id' => $branch->tenant_id, 'purchases.branch_id' => $branch->id, 'purchases.supplier_id' => $supplier])
                ->select('returned.id', 'returned.issued_at as effective_at', 'returned.issued_at as created_at', 'returned.document_no as reference')
                ->selectRaw("-returned.total AS signed_amount, 'purchase_return' AS source, 'purchase_return' AS kind");
            $entries = DB::query()->fromSub($opening->unionAll($payments)->unionAll($purchases)->unionAll($returns), 'events');
            if (isset($filters['as_of'])) {
                $entries->where('effective_at', '<=', $posting->date($filters['as_of']));
            }
            if (isset($filters['recorded_as_of'])) {
                $entries->where('created_at', '<=', $posting->date($filters['recorded_as_of']));
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
                'account_id' => $account?->id, 'opening_status' => $status,
                'opening_status_effective_at' => $status !== 'not_entered' ? $account?->opening_status_effective_at : null,
                'opening_confirmed_at' => $status !== 'not_entered' ? $account?->opening_confirmed_at : null,
                'balance' => $balance, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ]);
        });
    }

    public function post(Request $request, int $supplier, SupplierAccountPosting $posting, PostingTransaction $transaction): JsonResponse
    {
        $action = $request->route('operation');
        $ownerOnly = in_array($action, ['opening', 'zero', 'reverse-opening'], true);
        $permission = match ($action) {
            'reverse-payment', 'unapply' => 'payments.reverse',
            default => $ownerOnly ? 'opening.manage' : 'payments.pay_supplier',
        };
        abort_unless($request->user()->can($permission) && (! $ownerOnly || $request->user()->hasRole('Owner')), 403);
        $reason = ['reason' => 'required|string|max:255'];
        $date = ['effective_at' => 'required|date'];
        $amount = ['amount' => 'required|string'];
        $rules = match ($action) {
            'opening' => $reason + $date + $amount + [
                'entry_type' => 'required|in:due,credit,advance',
                'replaces_entry_id' => 'sometimes|integer|min:1',
                'reference' => 'sometimes|nullable|string|max:255', 'note' => 'sometimes|nullable|string|max:2000',
            ],
            'zero', 'reverse-opening' => $reason + $date,
            'payment', 'payment-preview' => $amount + [
                'mode' => 'required|in:cash,upi,card,bank', 'reference' => 'sometimes|nullable|string|max:255',
                'paid_at' => 'required_if:historical_entry,true|sometimes|required|date',
                'historical_entry' => 'sometimes|boolean', 'reason' => 'required_if:historical_entry,true|nullable|string|max:255',
                'included_in_opening_entry_id' => 'sometimes|integer|min:1', 'auto_allocate' => 'sometimes|boolean',
            ],
            'apply' => $amount + [
                'effective_at' => 'sometimes|required|date',
                'opening_entry_id' => 'required_without:purchase_id|prohibits:purchase_id|integer|min:1',
                'purchase_id' => 'required_without:opening_entry_id|prohibits:opening_entry_id|integer|min:1',
            ],
            'reverse-payment', 'unapply' => $reason,
            default => abort(404),
        };
        $data = $request->validate($rules);
        if ($action === 'payment-preview') {
            $branch = $request->attributes->get('branch');
            Supplier::where('tenant_id', $branch->tenant_id)->findOrFail($supplier);
            $account = DB::table('party_accounts')->where([
                'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'supplier_id' => $supplier,
            ])->first();

            return ApiResponse::data($posting->previewPayment($request, $account, $data));
        }
        $id = (int) $request->route('entry');
        $allocation = (int) $request->route('allocation');
        $intent = ['supplier_id' => $supplier, 'entry_id' => $id, 'allocation_id' => $allocation, 'data' => $data];

        return $transaction->run($request, 'supplier.'.$action, $intent, function () use ($request, $posting, $supplier, $action, $data, $id, $allocation) {
            $account = $posting->account($request, $supplier);
            $result = match ($action) {
                'opening' => $posting->opening($request, $account, $data),
                'zero' => $posting->status($request, $account, 'confirmed_zero', $posting->date($data['effective_at']), $data['reason']),
                'reverse-opening' => $posting->reverseOpening($request, $account, $id, $data),
                'payment' => $posting->payment($request, $account, $data),
                'apply' => ['allocation_id' => $posting->apply($request, $account, $id, $data + ['effective_at' => now()->toIso8601String()])],
                'reverse-payment' => $posting->reversePayment($request, $account, $id, $data['reason']),
                'unapply' => ['allocation_id' => $posting->unapply($request, $account, $id, $allocation, $data['reason'])],
            };

            return ApiResponse::data($result, 201);
        });
    }
}
