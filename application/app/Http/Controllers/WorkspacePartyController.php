<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspacePartyController
{
    public function entry(Request $request, string $kind, int $party, string $source, int $entry): JsonResponse
    {
        $customer = $kind === 'customers';
        abort_unless($request->user()->can($customer ? 'customers.view' : 'suppliers.manage'), 403);
        abort_unless(in_array($source, ['opening', $customer ? 'receipt' : 'payment'], true), 404);
        $branch = $request->attributes->get('branch');
        $scope = ['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, ($customer ? 'customer_id' : 'supplier_id') => $party];

        if ($source === 'opening') {
            $account = DB::table('party_accounts')->where($scope)->value('id');
            $record = DB::table('party_opening_entries')->where(['party_account_id' => $account, 'id' => $entry])->first();
            abort_unless($record, 404);
            $allocations = $customer && in_array($record->entry_type, ['credit', 'advance'], true)
                ? DB::table('party_credit_allocations')->where('party_opening_entry_id', $entry)->orderBy('id')->get() : [];
        } else {
            $record = DB::table('payments')->where($scope + ['id' => $entry])->first();
            abort_unless($record, 404);
            $original = $record->reversal_of_id ?? $entry;
            $allocations = DB::table('payment_allocations')->where('payment_id', $original)
                ->orWhereIn('reversal_of_id', DB::table('payment_allocations')->where('payment_id', $original)->select('id'))
                ->orderBy('id')->get();
        }

        $actor = DB::table('users')->where(['tenant_id' => $branch->tenant_id, 'id' => $record->created_by])->value('name');

        return ApiResponse::data(['record' => $record, 'allocations' => $allocations, 'actor' => $actor]);
    }
}
