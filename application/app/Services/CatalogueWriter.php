<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogueWriter
{
    /** Validate and normalize without writing. Commit callers hold the tenant lock. */
    public function prepare(int $tenant, ?int $id, array $input): array
    {
        $product = $id === null ? new Product : Product::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($id);
        $presence = $id === null ? 'required' : 'sometimes';
        $unique = fn (string $field) => Rule::unique('products', $field)->where('tenant_id', $tenant)->ignore($id);
        $reference = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenant)->where('is_active', true);
        $data = Validator::make($input, [
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', $unique('sku')],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', $unique('barcode')],
            'name' => [$presence, 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', $reference('categories')],
            'unit_id' => [$presence, 'required', 'integer', $reference('units')],
            'tax_rate_id' => [$presence, 'required', 'integer', $reference('tax_rates')],
            'hsn' => ['sometimes', 'nullable', 'regex:/\A(?:[0-9]{4}|[0-9]{6}|[0-9]{8})\z/'],
            'tax_inclusive' => [$presence, 'required', 'boolean'],
            'retail_price' => [$presence, 'required', 'string'],
            'wholesale_price' => [$presence, 'required', 'string'],
            'purchase_price' => ['sometimes', 'required', 'string'],
            'reorder_level' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'slabs' => ['sometimes', 'array', 'max:5'],
            'slabs.*' => ['array:min_qty,price'],
            'slabs.*.min_qty' => ['required', 'string'],
            'slabs.*.price' => ['required', 'string'],
            'opening_qty' => ['prohibited'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();
        $before = $product->exists ? $this->pricing($product) : null;
        foreach (['retail_price', 'wholesale_price', 'purchase_price'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Money::amount($data[$field]);
            }
        }
        $decimal = (bool) DB::table('units')->where('id', $data['unit_id'] ?? $product->unit_id)->value('allow_decimal');
        $reorder = $data['reorder_level'] ?? $product->reorder_level ?? '0';
        $data['reorder_level'] = preg_match('/\A0+(?:\.0{1,3})?\z/', $reorder) ? '0.000' : Money::quantity($reorder, $decimal);
        $slabs = [];
        foreach ($data['slabs'] ?? $before['slabs'] ?? [] as $slab) {
            $qty = Money::quantity($slab['min_qty'], $decimal);
            if (isset($slabs[$qty])) {
                throw ValidationException::withMessages(['slabs' => 'Minimum quantities must be unique.']);
            }
            $slabs[$qty] = ['min_qty' => $qty, 'price' => Money::amount($slab['price'])];
        }

        return [$product, $data, $slabs, $before];
    }

    public function persist(Request $request, array $prepared): Product
    {
        [$product, $data, $slabs, $before] = $prepared;
        $tenant = $request->user()->tenant_id;
        $id = $product->id;
        if (! isset($data['sku'])) {
            unset($data['sku']);
            if ($id === null) {
                $sequence = (int) DB::table('products')->max('id') + 1;
                do {
                    $data['sku'] = sprintf('P%06d', $sequence++);
                } while (DB::table('products')->where('tenant_id', $tenant)->where('sku', $data['sku'])->exists());
            }
        }
        $product->forceFill(collect($data)->except(['slabs', 'reason'])->all() + ['tenant_id' => $tenant])->save();
        if (isset($data['slabs'])) {
            DB::table('product_price_slabs')->where('product_id', $product->id)->delete();
            if ($slabs !== []) {
                DB::table('product_price_slabs')->insert(array_map(fn (array $slab) => $slab + ['product_id' => $product->id], array_values($slabs)));
            }
        }
        if ($id === null) {
            CatalogueBalances::forProduct($product);
        }
        $product->refresh();
        $after = $this->pricing($product);
        if ($before !== $after) {
            app(PostingAudit::class)->record($request, 'product.pricing', 'product', $product->id, $before, $after, $data['reason'] ?? null);
        }

        return $product->setAttribute('slabs', $after['slabs']);
    }

    public function pricing(Product $product): array
    {
        return $product->only(['retail_price', 'wholesale_price', 'purchase_price', 'tax_inclusive', 'tax_rate_id']) + [
            'rate_bp' => DB::table('tax_rates')->where('id', $product->tax_rate_id)->value('rate_bp'),
            'slabs' => DB::table('product_price_slabs')->where('product_id', $product->id)->orderBy('min_qty')
                ->get(['min_qty', 'price'])->map(fn (object $slab) => (array) $slab)->all(),
        ];
    }
}
