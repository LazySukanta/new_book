<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Middleware\ResolveBranch;
use App\Models\Product;
use App\Services\CatalogueWriter;
use App\Services\PostingTransaction;
use App\Services\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogueController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('products.view'), 403);
        $filters = $request->validate([
            'sku' => 'sometimes|required|string|max:100',
            'barcode' => 'sometimes|required|string|max:100',
            'search' => 'sometimes|required|string|max:100',
            'category_id' => 'sometimes|integer|min:1',
            'active' => 'sometimes|boolean',
            'low_stock' => 'sometimes|boolean',
            'with_stock' => 'sometimes|boolean',
        ]);
        $query = Product::where('tenant_id', $request->user()->tenant_id);
        if (! $request->user()->can('products.manage')) {
            $query->where('is_active', true)->select([
                'id', 'sku', 'barcode', 'name', 'category_id', 'unit_id', 'tax_rate_id', 'hsn',
                'tax_inclusive', 'retail_price', 'wholesale_price', 'reorder_level', 'is_active',
            ]);
        }
        foreach (['sku', 'barcode', 'category_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['active'])) {
            $query->where('is_active', $filters['active']);
        }
        if (isset($filters['search'])) {
            $prefix = addcslashes($filters['search'], '\\%_').'%';
            $query->where(fn ($products) => $products->whereLike('name', $prefix)->orWhereLike('sku', $prefix)->orWhereLike('barcode', $prefix));
        }

        if ($request->boolean('low_stock') || $request->boolean('with_stock')) {
            return app(ResolveBranch::class)->handle($request, function (Request $request) use ($query) {
                $branchId = $request->attributes->get('branch')->id;
                $query->whereExists(function ($balances) use ($request, $branchId) {
                    $balances->selectRaw('1')->from('stock_balances')->where('branch_id', $branchId)
                        ->whereColumn('product_id', 'products.id');
                    if ($request->boolean('low_stock')) {
                        $balances->whereColumn('qty', '<=', 'products.reorder_level');
                    }
                    if ($request->boolean('with_stock')) {
                        $balances->where('qty', '>', 0);
                    }
                });

                return ApiResponse::page($request, $query, ['name', 'sku']);
            });
        }

        return ApiResponse::page($request, $query, ['name', 'sku']);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->hasAny(['opening_qty', 'opening_unit_cost'])) {
            return $this->storeWithOpeningStock($request);
        }

        return $this->save($request, null);
    }

    private function storeWithOpeningStock(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('products.manage') && $user->can('prices.manage') && $user->can('stock.adjust'), 403);
        $data = $request->validate([
            'opening_qty' => 'required|string',
            'opening_unit_cost' => 'required|string',
            'posted_at' => 'prohibited',
            'date' => 'prohibited',
            'effective_at' => 'prohibited',
        ]);
        $catalogue = $request->except(['opening_qty', 'opening_unit_cost']);

        return app(ResolveBranch::class)->handle($request, function (Request $request) use ($user, $data, $catalogue) {
            return app(PostingTransaction::class)->run($request, 'products.create_with_opening', $data + $catalogue, function () use ($request, $user, $data, $catalogue) {
                DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->first();
                $branch = $request->attributes->get('branch');
                $user->accessibleBranches()->lockForUpdate()->findOrFail($branch->id);
                $writer = app(CatalogueWriter::class);
                $product = $writer->persist($request, $writer->prepare($user->tenant_id, null, $catalogue));
                $decimal = (bool) DB::table('units')->where('id', $product->unit_id)->value('allow_decimal');
                $adjustment = app(StockAdjustment::class);
                $item = $adjustment->openingItem($product->id, $data['opening_qty'], $data['opening_unit_cost'], $decimal);

                return ApiResponse::data($product->setAttribute('opening_stock', $adjustment->post($request, 'opening', 'Opening stock', null, [$item])), 201);
            });
        });
    }

    public function lookup(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('products.view'), 403);
        $code = $request->validate(['code' => 'required|string|max:100'])['code'];
        $product = Product::where('tenant_id', $request->user()->tenant_id)->where('is_active', true)
            ->where(fn ($query) => $query->where('barcode', $code)->orWhere('sku', $code))
            ->orderByRaw('CASE WHEN barcode = ? THEN 0 ELSE 1 END', [$code])->firstOrFail();
        $product->makeHidden('purchase_price');
        $product->setAttribute('slabs', app(CatalogueWriter::class)->pricing($product)['slabs']);
        $product->setAttribute('stock_qty', DB::table('stock_balances')->where([
            'branch_id' => $request->attributes->get('branch')->id, 'product_id' => $product->id,
        ])->value('qty') ?? '0.000');

        return ApiResponse::data($product);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        abort_unless($request->user()->can('products.manage'), 403);

        return DB::transaction(function () use ($request, $product) {
            $record = Product::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($product);
            if (Schema::hasTable('stock_ledger') && DB::table('stock_ledger')->where('product_id', $product)->exists()) {
                return ApiResponse::conflict('PRODUCT_HAS_HISTORY', 'Deactivate this product to retain its stock history.');
            }
            $record->delete();

            return ApiResponse::data(null);
        }, 3);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        return $this->save($request, $product);
    }

    public function history(Request $request, int $product): JsonResponse
    {
        abort_unless($request->user()->can('prices.manage'), 403);
        $tenant = $request->user()->tenant_id;
        Product::where('tenant_id', $tenant)->findOrFail($product);
        $data = DB::table('audit_logs')->where([
            'tenant_id' => $tenant, 'entity_type' => 'product', 'entity_id' => $product, 'action' => 'product.pricing',
        ])->orderByDesc('id')->paginate(25);
        $rows = $data->getCollection()->map(function (object $row) {
            $row->before_json = $row->before_json === null ? null : json_decode($row->before_json, true, flags: JSON_THROW_ON_ERROR);
            $row->after_json = json_decode($row->after_json, true, flags: JSON_THROW_ON_ERROR);

            return $row;
        });

        return ApiResponse::data($rows, meta: ['current_page' => $data->currentPage(), 'last_page' => $data->lastPage(), 'total' => $data->total()]);
    }

    private function save(Request $request, ?int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('products.manage') && $user->can('prices.manage'), 403);

        return DB::transaction(function () use ($request, $user, $id) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->first();
            $writer = app(CatalogueWriter::class);
            $product = $writer->persist($request, $writer->prepare($user->tenant_id, $id, $request->all()));

            return ApiResponse::data($product, $id === null ? 201 : 200);
        }, 3);
    }
}
