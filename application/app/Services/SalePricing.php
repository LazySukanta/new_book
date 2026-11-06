<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalePricing
{
    public function calculate(User $actor, Branch $branch, array $input): array
    {
        abort_unless($actor->can('sales.create') && $branch->tenant_id === $actor->tenant_id, 403);
        $rules = [
            'sale_type' => 'sometimes|required|in:retail,wholesale',
            'customer_id' => 'sometimes|nullable|integer|min:1',
            'place_of_supply_override' => 'sometimes|nullable|string|exists:gst_states,code',
            'items' => 'required|array|list|min:1|max:100',
            'items.*' => 'array:product_id,qty,unit_price,discount',
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'items.*.qty' => 'required|string',
            'items.*.unit_price' => 'sometimes|nullable|string',
        ];
        foreach (['bill_discount', 'items.*.discount'] as $key) {
            $rules[$key] = 'sometimes|nullable|array:type,value,value_bp';
            $rules[$key.'.type'] = 'sometimes|required|in:amount,percent';
            $rules[$key.'.value'] = 'sometimes|required|string';
            $rules[$key.'.value_bp'] = 'sometimes|required|integer|between:0,10000';
        }
        $data = Validator::make($input, $rules)->validate();
        $customer = Customer::where(['tenant_id' => $actor->tenant_id, 'is_active' => true])
            ->when(isset($data['customer_id']), fn ($query) => $query->whereKey($data['customer_id']), fn ($query) => $query->where('is_system', true))
            ->firstOrFail();
        $saleType = $data['sale_type'] ?? $customer->preferred_sale_type;
        $settings = DB::table('shop_settings')->where('tenant_id', $actor->tenant_id)->first();
        $registration = $settings->gst_registration_type ?? 'regular';
        $override = $data['place_of_supply_override'] ?? null;
        abort_if($override !== null && ! $actor->can('sales.override_place_of_supply'), 403);
        $supply = $override ?? $branch->state_code;
        if ($branch->state_code === null || ($registration !== 'unregistered' && (! $branch->gstin || substr($branch->gstin, 0, 2) !== $branch->state_code))) {
            throw ValidationException::withMessages(['branch' => 'Configure the branch state and matching GSTIN for its registration.']);
        }
        $interstate = $supply !== $branch->state_code;
        if ($registration === 'composition' && $interstate) {
            throw ValidationException::withMessages(['place_of_supply_override' => 'Interstate composition sales are unavailable.']);
        }

        $ids = array_column($data['items'], 'product_id');
        $products = Product::where(['tenant_id' => $actor->tenant_id, 'is_active' => true])->whereIn('id', $ids)->get()->keyBy('id');
        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Select distinct active products from this shop.']);
        }
        $units = DB::table('units')->whereIn('id', $products->pluck('unit_id'))->pluck('allow_decimal', 'id');
        $rates = DB::table('tax_rates')->whereIn('id', $products->pluck('tax_rate_id'))->pluck('rate_bp', 'id');
        $slabs = $saleType === 'wholesale'
            ? DB::table('product_price_slabs')->whereIn('product_id', $ids)->orderByDesc('min_qty')->get()->groupBy('product_id')
            : collect();
        $lines = [];
        $bases = [];
        $subtotal = BigDecimal::zero()->toScale(2);
        $lineDiscount = $subtotal;
        foreach ($data['items'] as $index => $item) {
            $product = $products[$item['product_id']];
            $qty = Money::quantity($item['qty'], (bool) $units[$product->unit_id]);
            $slab = $slabs->get($product->id)?->first(fn ($row) => BigDecimal::of($qty)->isGreaterThanOrEqualTo($row->min_qty));
            $priceSource = $slab ? 'wholesale_slab' : $saleType;
            $price = $slab->price ?? ($saleType === 'retail' ? $product->retail_price : $product->wholesale_price);
            if (isset($item['unit_price'])) {
                abort_unless($actor->can('sales.override_price'), 403);
                $price = Money::amount($item['unit_price']);
                $priceSource = 'override';
            }
            if (($settings->hsn_required ?? false) && ! $product->hsn) {
                throw ValidationException::withMessages(["items.$index.product_id" => 'This product needs an HSN code.']);
            }
            $gross = BigDecimal::of(Money::lineAmount($price, $qty));
            $discount = $this->discount($gross, $item['discount'] ?? null, "items.$index.discount");
            $subtotal = $subtotal->plus($gross);
            $lineDiscount = $lineDiscount->plus($discount);
            $bases[] = $gross->minus($discount);
            $lines[] = [
                'product_id' => $product->id, 'qty' => $qty, 'unit_price' => $price, 'price_source' => $priceSource,
                'slab_min_qty' => $priceSource === 'wholesale_slab' ? $slab->min_qty : null,
                'price_includes_tax' => $product->tax_inclusive, 'rate_bp' => $registration === 'regular' ? (int) $rates[$product->tax_rate_id] : 0,
                'hsn' => $product->hsn, 'gross' => (string) $gross, 'line_discount' => (string) $discount,
            ];
        }

        $taxable = count(array_filter(array_column($lines, 'rate_bp')));
        if ($taxable > 0 && $taxable < count($lines)) {
            throw ValidationException::withMessages(['items' => 'This preview cannot mix taxable and zero-rate products.']);
        }
        $documentType = match (true) {
            $registration === 'unregistered' => 'commercial_bill',
            $registration === 'composition', $taxable === 0 => 'bill_of_supply',
            default => 'tax_invoice',
        };
        $billDiscount = $this->discount($subtotal->minus($lineDiscount), $data['bill_discount'] ?? null, 'bill_discount');
        $totalDiscount = $lineDiscount->plus($billDiscount);
        if ($totalDiscount->isPositive()) {
            abort_unless($actor->can('sales.discount'), 403);
            if (! $actor->hasAnyRole(['Owner', 'Manager']) && $totalDiscount->multipliedBy(10000)->isGreaterThan(
                $subtotal->multipliedBy($settings->max_cashier_discount_bp ?? 500)
            )) {
                throw ValidationException::withMessages(['bill_discount' => 'The combined discount exceeds the cashier limit.']);
            }
        }
        $shares = InvoiceArithmetic::allocate($bases, (string) $billDiscount);
        $totals = array_fill_keys(['taxable', 'cgst', 'sgst', 'igst', 'total'], BigDecimal::zero()->toScale(2));
        foreach ($lines as $index => &$line) {
            $tax = InvoiceArithmetic::tax($bases[$index]->minus($shares[$index]), $line['rate_bp'], $line['price_includes_tax'], $interstate ? 'inter' : 'intra');
            foreach ($tax as $key => $amount) {
                $totals[$key] = $totals[$key]->plus($amount);
            }
            $line += ['bill_discount_share' => (string) $shares[$index], 'line_total' => Money::amount((string) $tax['total'])]
                + array_map(fn ($amount) => Money::amount((string) $amount), array_diff_key($tax, ['total' => true]));
        }
        unset($line);

        $snapshot = [
            'branch_id' => $branch->id, 'branch_state_code' => $branch->state_code, 'branch_gstin' => $branch->gstin,
            'customer_id' => $customer->id, 'customer_gstin' => $customer->gstin,
            'sale_type' => $saleType, 'gst_registration_type' => $registration, 'document_type' => $documentType,
            'place_of_supply_code' => $supply, 'place_of_supply_overridden' => $override !== null, 'is_interstate' => $interstate,
            'items' => $lines, 'totals' => array_map(fn ($amount) => Money::amount((string) $amount), $totals + [
                'subtotal' => $subtotal, 'line_discount_total' => $lineDiscount, 'bill_discount' => $billDiscount, 'round_off' => '0.00',
            ]),
        ];

        return $snapshot + ['pricing_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    private function discount(BigDecimal $base, ?array $intent, string $field): BigDecimal
    {
        $amount = match ($intent['type'] ?? null) {
            'amount' => isset($intent['value']) && ! isset($intent['value_bp']) ? BigDecimal::of(Money::amount($intent['value'])) : null,
            'percent' => isset($intent['value_bp']) && ! isset($intent['value'])
                ? $base->multipliedBy((int) $intent['value_bp'])->dividedBy(10000, 2, RoundingMode::HalfUp) : null,
            default => $intent === null ? BigDecimal::zero()->toScale(2) : null,
        };
        if ($amount === null || $amount->isGreaterThan($base)) {
            throw ValidationException::withMessages([$field => 'Enter a valid discount within the available amount.']);
        }

        return $amount;
    }
}
