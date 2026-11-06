<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockPosting
{
    public function receive(User $actor, int $branch, int $product, int $purchaseItem, string $quantity, string $unitCost, ?string $inventoryValue = null): object
    {
        return $this->post($actor, $branch, $product, $purchaseItem, $quantity, $this->cost($unitCost), null, $inventoryValue);
    }

    public function issueSale(User $actor, int $branch, int $product, int $saleItem, string $quantity, ?string $approvedEstimate = null): object
    {
        return $this->post($actor, $branch, $product, $saleItem, $quantity, null, $approvedEstimate === null ? null : $this->cost($approvedEstimate));
    }

    private function post(User $actor, int $branchId, int $productId, int $source, string $quantity, ?BigDecimal $receiptCost, ?BigDecimal $estimate, ?string $inventoryValue = null): object
    {
        if ($source < 1) {
            throw ValidationException::withMessages(['source' => 'A valid source item is required.']);
        }

        return DB::transaction(function () use ($actor, $branchId, $productId, $source, $quantity, $receiptCost, $estimate, $inventoryValue) {
            abort_unless($actor->is_active, 403);
            $branch = $actor->accessibleBranches()->lockForUpdate()->findOrFail($branchId);
            $product = Product::where('tenant_id', $actor->tenant_id)->lockForUpdate()->findOrFail($productId);
            $decimal = (bool) DB::table('units')->where('id', $product->unit_id)->value('allow_decimal');
            $qty = BigDecimal::of(Money::quantity($quantity, $decimal));
            $scope = ['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'product_id' => $product->id];
            $projection = app(StockProjection::class);
            $balance = $projection->lock($scope);
            $beforeQty = BigDecimal::of($balance->qty);
            $beforeValue = BigDecimal::of($balance->inventory_value);
            $last = $balance->last_known_unit_cost;
            $estimatedQty = BigDecimal::zero()->toScale(3);
            $estimatedCost = null;
            $variance = BigDecimal::zero()->toScale(2);

            if ($receiptCost !== null) {
                $delta = $qty;
                $afterQty = $beforeQty->plus($qty);
                abort_unless(DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                    ->where(['purchase_items.id' => $source, 'purchase_items.product_id' => $product->id,
                        'purchases.tenant_id' => $actor->tenant_id, 'purchases.branch_id' => $branch->id])->exists(), 404);
                $acquisition = $inventoryValue === null ? $this->round($qty->multipliedBy($receiptCost)) : BigDecimal::of(Money::amount($inventoryValue));
                $afterValue = $beforeValue->plus($acquisition);
                if ($beforeQty->isNegative()) {
                    $afterValue = $afterQty->isNegative()
                        ? $beforeValue->multipliedBy($afterQty)->dividedBy($beforeQty, 2, RoundingMode::HalfUp)
                        : $acquisition->multipliedBy($afterQty)->dividedBy($qty, 2, RoundingMode::HalfUp);
                    $variance = $afterValue->minus($beforeValue)->minus($acquisition);
                }
                $last = (string) $receiptCost;
                $unitCost = $receiptCost;
                $costSource = 'receipt';
                $type = 'purchase_receipt';
                $sourceField = 'purchase_item_id';
            } else {
                $delta = $qty->negated();
                $afterQty = $beforeQty->minus($qty);
                $available = BigDecimal::min($qty, BigDecimal::max($beforeQty, BigDecimal::zero()));
                $estimatedQty = $qty->minus($available);
                $actualValue = BigDecimal::zero()->toScale(2);
                if (! $available->isZero()) {
                    $actualValue = $available->isEqualTo($beforeQty) ? $beforeValue
                        : $beforeValue->multipliedBy($available)->dividedBy($beforeQty, 2, RoundingMode::HalfUp);
                }
                $costSource = 'carrying_value';
                $estimatedValue = BigDecimal::zero()->toScale(2);
                if (! $estimatedQty->isZero()) {
                    $allowed = DB::table('shop_settings')->where('tenant_id', $actor->tenant_id)->value('allow_negative_stock') ?? true;
                    if (! $allowed) {
                        throw ValidationException::withMessages(['qty' => 'Insufficient stock for this sale.']);
                    }
                    [$estimatedCost, $costSource] = match (true) {
                        $last !== null => [BigDecimal::of($last), 'last_known'],
                        BigDecimal::of($product->purchase_price)->isPositive() => [BigDecimal::of($product->purchase_price), 'product_purchase'],
                        $estimate !== null => [$estimate, 'approved_estimate'],
                        default => [BigDecimal::zero(), 'unknown_zero'],
                    };
                    $estimatedValue = $this->round($estimatedQty->multipliedBy($estimatedCost));
                }
                $issuedValue = $actualValue->plus($estimatedValue);
                $afterValue = $beforeValue->minus($issuedValue);
                $unitCost = $issuedValue->dividedBy($qty, 9, RoundingMode::HalfUp);
                $type = 'sale_issue';
                $sourceField = 'sale_item_id';
            }

            $valueChange = $afterValue->minus($beforeValue);
            $movement = [
                'type' => $type, 'qty_change' => (string) $delta, 'value_change' => (string) $valueChange,
                'qty_after' => (string) $afterQty, 'value_after' => (string) $afterValue,
                'valuation_variance' => (string) $variance, 'unit_cost' => (string) $unitCost,
                'cost_estimated' => ! $estimatedQty->isZero(), 'estimated_qty' => (string) $estimatedQty,
                'estimated_unit_cost' => $estimatedCost === null ? null : (string) $estimatedCost,
                'cost_source' => $costSource, $sourceField => $source, 'created_by' => $actor->id, 'posted_at' => now(),
            ];

            return $projection->append($scope, $movement, $last);
        }, 3);
    }

    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HalfUp);
    }

    private function cost(string $value): BigDecimal
    {
        if (! preg_match('/\A[0-9]{1,15}(?:\.[0-9]{1,9})?\z/', $value)) {
            throw ValidationException::withMessages(['unit_cost' => 'Use a nonnegative decimal unit cost with up to nine decimal places.']);
        }

        return BigDecimal::of($value)->toScale(9);
    }
}
