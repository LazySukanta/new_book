<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CatalogueBalances
{
    public static function forProduct(Product $product): void
    {
        Branch::where('tenant_id', $product->tenant_id)->where('is_active', true)->pluck('id')->chunk(500)
            ->each(fn ($ids) => DB::table('stock_balances')->insertOrIgnore($ids->map(fn ($id) => [
                'tenant_id' => $product->tenant_id, 'branch_id' => $id, 'product_id' => $product->id,
            ])->all()));
    }

    public static function forBranch(Branch $branch): void
    {
        Product::where('tenant_id', $branch->tenant_id)->select('id')->chunkById(500, function ($products) use ($branch) {
            DB::table('stock_balances')->insertOrIgnore($products->map(fn (Product $product) => [
                'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'product_id' => $product->id,
            ])->all());
        });
    }
}
