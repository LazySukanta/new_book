<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\PostingTransaction;
use App\Services\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StockAdjustmentController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('stock.adjust'), 403);
        $branch = $request->attributes->get('branch');
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100']);
        $page = DB::table('stock_adjustments')->where('tenant_id', $branch->tenant_id)->where('branch_id', $branch->id)
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 25);
        $items = DB::table('stock_adjustment_items')->whereIn('adjustment_id', $page->getCollection()->pluck('id'))
            ->orderBy('product_id')->get()->groupBy('adjustment_id');
        $page->getCollection()->each(function (object $row) use ($items) {
            $row->items = $items[$row->id] ?? [];
        });

        return ApiResponse::data($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        return $this->store($request, false);
    }

    public function count(Request $request): JsonResponse
    {
        return $this->store($request, true);
    }

    private function store(Request $request, bool $count): JsonResponse
    {
        abort_unless($request->user()->can('stock.adjust'), 403);
        $kind = $count ? 'count_correction' : 'adjustment';
        $items = $count ? 'product_id,counted_qty,unit_cost,expected_last_ledger_id' : 'product_id,qty_change,unit_cost';
        $rules = [
            'reason' => 'required|string|max:255',
            'note' => 'sometimes|nullable|string|max:2000',
            'corrects_adjustment_id' => 'sometimes|nullable|integer|min:1',
            'posted_at' => 'prohibited',
            'date' => 'prohibited',
            'effective_at' => 'prohibited',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'array:'.$items,
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'items.*.unit_cost' => 'sometimes|nullable|string',
        ];
        if ($count) {
            $rules['items.*.counted_qty'] = 'required|string';
            $rules['items.*.expected_last_ledger_id'] = 'present|nullable|integer|min:1';
        } else {
            $rules['items.*.qty_change'] = 'required|string';
        }
        $data = $request->validate($rules);
        foreach ($data['items'] as &$item) {
            $item['product_id'] = (int) $item['product_id'];
            if (isset($item['expected_last_ledger_id'])) {
                $item['expected_last_ledger_id'] = (int) $item['expected_last_ledger_id'];
            }
        }

        return app(PostingTransaction::class)->run($request, 'stock.'.$kind, $data, function () use ($request, $kind, $data) {
            return ApiResponse::data(app(StockAdjustment::class)->post($request, $kind, $data['reason'],
                $data['note'] ?? null, $data['items'], $data['corrects_adjustment_id'] ?? null), 201);
        });
    }
}
