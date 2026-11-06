<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\PostingTransaction;
use App\Services\PurchaseCorrectionPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PurchaseCorrectionController
{
    public function handle(Request $request, int $purchase, PurchaseCorrectionPosting $posting, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('purchases.revise'), 403);
        $preview = $request->route('operation') === 'preview';
        $delete = $request->isMethod('delete') || $request->route('operation') === 'cancel';
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1', 'reason' => 'required|string|max:255',
            'action' => 'sometimes|in:update,soft_delete',
            'preview_hash' => ($preview ? 'sometimes' : 'required').'|string|size:64',
            'supplier_id' => 'sometimes|required|integer|min:1',
            'supplier_invoice_no' => 'sometimes|required|string|max:120',
            'supplier_invoice_date' => 'sometimes|required|date_format:Y-m-d',
            'cost_includes_tax' => 'sometimes|required|boolean', 'input_tax_recoverable' => 'sometimes|required|boolean',
            'tax_type' => 'sometimes|required|in:intra,inter', 'discount_amount' => 'sometimes|required|string',
            'items' => 'sometimes|required|array|min:1|max:100',
            'items.*' => 'array:product_id,qty,unit_cost,rate_bp,confirmed_remaining',
            'items.*.product_id' => 'required|integer|min:1|distinct', 'items.*.qty' => 'required|string',
            'items.*.unit_cost' => 'required|string', 'items.*.rate_bp' => 'required|integer|between:0,10000',
            'items.*.confirmed_remaining' => 'sometimes|required|string',
            'payments' => 'prohibited', 'received_at' => 'prohibited', 'financial_effective_at' => 'prohibited',
        ]);
        $data['action'] = $delete ? 'soft_delete' : ($data['action'] ?? 'update');
        if ($preview) {
            return DB::transaction(fn () => ApiResponse::data($posting->prepare($request, $purchase, $data)), 3);
        }

        return $transaction->run($request, 'purchase.revise', ['purchase_id' => $purchase, 'data' => $data],
            fn (int $id) => $posting->commit($request, $purchase, $data, $id));
    }

    public function history(Request $request, int $purchase): JsonResponse
    {
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('purchases.revise'), 403);
        $branch = $request->attributes->get('branch');
        abort_unless(DB::table('purchases')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'id' => $purchase])->exists(), 404);
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100']);
        $page = DB::table('purchase_revisions')->where('purchase_id', $purchase)->orderByDesc('revision_no')->paginate($data['per_page'] ?? 25);
        $rows = array_map(function (object $row) {
            foreach (['before_snapshot', 'after_snapshot', 'allocation_changes_snapshot'] as $field) {
                $row->$field = $row->$field === null ? null : json_decode($row->$field, true, flags: JSON_THROW_ON_ERROR);
            }

            return $row;
        }, $page->items());

        return ApiResponse::data($rows, meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
