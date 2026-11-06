<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StockController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('stock.view'), 403);
        $branch = $request->attributes->get('branch');
        $query = DB::table('stock_balances as balances')->join('products', 'products.id', '=', 'balances.product_id')
            ->where('balances.tenant_id', $branch->tenant_id)->where('balances.branch_id', $branch->id)
            ->whereNull('products.deleted_at')->orderBy('products.name')->orderBy('products.id')
            ->select('products.id as product_id', 'products.sku', 'products.name', 'products.reorder_level',
                'products.is_active', 'balances.qty', 'balances.valuation_provisional', 'balances.last_ledger_id');
        if ($request->user()->can('reports.view')) {
            $query->addSelect('balances.inventory_value', 'balances.avg_cost');
        }

        return $this->page($request, $query);
    }

    public function ledger(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('stock.view'), 403);
        $filters = $request->validate(['product_id' => 'sometimes|required|integer|min:1']);
        $branch = $request->attributes->get('branch');
        $query = DB::table('stock_ledger')->where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->id)->orderByDesc('id');
        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (! $request->user()->can('reports.view')) {
            $query->select('id', 'product_id', 'type', 'qty_change', 'qty_after', 'cost_estimated', 'posted_at');
        }

        return $this->page($request, $query);
    }

    private function page(Request $request, Builder $query): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100']);
        $page = $query->paginate($data['per_page'] ?? 25);

        return ApiResponse::data($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }
}
