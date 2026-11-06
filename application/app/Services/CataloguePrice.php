<?php

namespace App\Services;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class CataloguePrice
{
    public function resolve(Product $product, string $quantity, bool $wholesale): string
    {
        $allowDecimal = (bool) DB::table('units')->where('id', $product->unit_id)->value('allow_decimal');
        $quantity = Money::quantity($quantity, $allowDecimal);

        if (! $wholesale) {
            return $product->retail_price;
        }

        $slab = DB::table('product_price_slabs')->where('product_id', $product->id)
            ->where('min_qty', '<=', $quantity)->orderByDesc('min_qty')->value('price');

        return $slab === null ? $product->wholesale_price : (string) BigDecimal::of($slab)->toScale(2);
    }
}
