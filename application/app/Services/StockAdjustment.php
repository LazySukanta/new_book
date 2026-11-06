<?php

namespace App\Services;

use App\Http\ApiResponse;
use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockAdjustment
{
    public function post(Request $request, string $kind, string $reason, ?string $note, array $items, ?int $corrects = null): array
    {
        $actor = $request->user();
        $branch = $request->attributes->get('branch');
        abort_unless($actor->can('stock.adjust'), 403);
        $actor->accessibleBranches()->lockForUpdate()->findOrFail($branch->id);
        if ($corrects !== null) {
            abort_unless(DB::table('stock_adjustments')->where([
                'id' => $corrects, 'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id,
            ])->exists(), 404);
        }
        $products = Product::where('tenant_id', $actor->tenant_id)->whereIn('id', array_column($items, 'product_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($items)) {
            throw ValidationException::withMessages(['items' => 'Select distinct products from this shop.']);
        }
        $units = DB::table('units')->whereIn('id', $products->pluck('unit_id'))->pluck('allow_decimal', 'id');
        usort($items, fn (array $a, array $b) => $a['product_id'] <=> $b['product_id']);
        $now = now();
        $id = DB::table('stock_adjustments')->insertGetId([
            'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'created_by' => $actor->id,
            'device_id' => $request->attributes->get('device')?->id, 'kind' => $kind,
            'reason' => $reason, 'note' => $note, 'posted_at' => $now, 'corrects_adjustment_id' => $corrects,
        ]);
        $projection = app(StockProjection::class);
        $results = [];
        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            $scope = ['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'product_id' => $product->id];
            $balance = $projection->lock($scope);
            $decimal = (bool) $units[$product->unit_id];
            if (($kind === 'count_correction' || array_key_exists('expected_last_ledger_id', $item)) && ($item['expected_last_ledger_id'] ?? null) !== $balance->last_ledger_id) {
                throw new HttpResponseException(ApiResponse::conflict('STALE_STOCK_COUNT', 'Stock changed after this count began. Refresh and confirm the new balance.'));
            }
            if ($kind === 'opening' && $this->hasOpening($branch->id, $product->id)) {
                throw new HttpResponseException(ApiResponse::conflict('OPENING_STOCK_EXISTS', 'Opening stock was already recorded. Use a new stock correction.'));
            }
            $data = $this->calculate($kind, $item, $balance, $decimal);
            $itemId = DB::table('stock_adjustment_items')->insertGetId($data + ['adjustment_id' => $id, 'product_id' => $product->id]);
            $ledgerId = null;
            if ($data['qty_change'] !== '0.000' || $data['value_change'] !== '0.00') {
                $movement = array_intersect_key($data, array_flip([
                    'qty_change', 'qty_after', 'value_change', 'value_after', 'valuation_variance', 'unit_cost', 'cost_source',
                ]));
                $ledgerId = $projection->append($scope, $movement + [
                    'type' => $kind, 'adjustment_item_id' => $itemId, 'created_by' => $actor->id, 'posted_at' => $now,
                ], isset($item['unit_cost']) ? $data['unit_cost'] : $balance->last_known_unit_cost)->id;
            }
            $results[] = $data + ['id' => $itemId, 'product_id' => $product->id, 'ledger_id' => $ledgerId];
        }
        app(PostingAudit::class)->record($request, 'stock.adjusted', 'stock_adjustment', $id, null, ['kind' => $kind, 'items' => $results], $reason);

        return ['id' => $id, 'kind' => $kind, 'reason' => $reason, 'posted_at' => $now, 'items' => $results];
    }

    public function preview(Request $request, string $kind, array $item): array
    {
        abort_unless($request->user()->can('stock.adjust'), 403);
        $branch = $request->attributes->get('branch');
        $product = Product::where('tenant_id', $branch->tenant_id)->findOrFail($item['product_id']);
        $balance = DB::table('stock_balances')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'product_id' => $product->id])->first()
            ?? (object) ['qty' => '0.000', 'inventory_value' => '0.00', 'avg_cost' => '0.000000000', 'last_ledger_id' => null];
        $decimal = (bool) DB::table('units')->where('id', $product->unit_id)->value('allow_decimal');

        $preview = $this->calculate($kind, $item, $balance, $decimal);

        return $preview + [
            'last_ledger_id' => $balance->last_ledger_id,
            'avg_cost_before' => $balance->avg_cost,
            'avg_cost_after' => (string) app(StockProjection::class)->average(BigDecimal::of($preview['qty_after']), BigDecimal::of($preview['value_after'])),
        ];
    }

    public function openingItem(int $product, string $quantity, string $unitCost, bool $decimal): array
    {
        return ['product_id' => $product, 'qty_change' => Money::quantity($quantity, $decimal), 'unit_cost' => Money::amount($unitCost)];
    }

    public function hasOpening(int $branch, int $product): bool
    {
        return DB::table('stock_adjustment_items as item')
            ->join('stock_adjustments as adjustment', 'adjustment.id', '=', 'item.adjustment_id')
            ->where(['adjustment.branch_id' => $branch, 'adjustment.kind' => 'opening', 'item.product_id' => $product])->exists();
    }

    private function calculate(string $kind, array $item, object $balance, bool $decimal): array
    {
        $beforeQty = BigDecimal::of($balance->qty);
        $beforeValue = BigDecimal::of($balance->inventory_value);
        $counted = $kind === 'count_correction' ? $this->quantity($item['counted_qty'], $decimal) : null;
        $change = $counted === null ? $this->quantity($item['qty_change'], $decimal) : $counted->minus($beforeQty);
        $afterQty = $beforeQty->plus($change);
        if (($counted !== null && $counted->isNegative()) || ($change->isNegative() && $afterQty->isNegative())) {
            throw ValidationException::withMessages(['qty' => 'A count or stock removal cannot result in negative stock.']);
        }
        $cost = isset($item['unit_cost']) ? BigDecimal::of(Money::amount($item['unit_cost']))->toScale(9) : null;
        if ($change->isPositive() && $cost === null) {
            throw ValidationException::withMessages(['unit_cost' => 'Stock additions require an explicit unit cost.']);
        }
        if ($change->isZero() && $cost !== null && $afterQty->isNegative()) {
            throw ValidationException::withMessages(['qty' => 'Revalue negative stock with a physical count.']);
        }
        if ($counted === null && $change->isZero() && $cost === null) {
            throw ValidationException::withMessages(['qty_change' => 'Enter a nonzero change or an explicit cost correction.']);
        }
        $basis = $cost ?? BigDecimal::of($balance->avg_cost);
        $variance = BigDecimal::zero()->toScale(2);
        if ($cost !== null && ($counted !== null || $change->isZero())) {
            $afterValue = $this->round($afterQty->multipliedBy($cost));
            $variance = $afterValue->minus($beforeValue)->minus($this->round($change->multipliedBy($cost)));
        } elseif ($change->isPositive()) {
            $added = $this->round($change->multipliedBy($basis));
            $afterValue = $beforeValue->plus($added);
            if ($beforeQty->isNegative()) {
                $afterValue = $afterQty->isNegative()
                    ? $beforeValue->multipliedBy($afterQty)->dividedBy($beforeQty, 2, RoundingMode::HalfUp)
                    : $this->round($afterQty->multipliedBy($basis));
                $variance = $afterValue->minus($beforeValue)->minus($added);
            }
        } elseif ($change->isNegative()) {
            $afterValue = $afterQty->isZero() ? BigDecimal::zero()->toScale(2)
                : $beforeValue->multipliedBy($afterQty)->dividedBy($beforeQty, 2, RoundingMode::HalfUp);
            $basis = $beforeValue->minus($afterValue)->dividedBy($change->abs(), 9, RoundingMode::HalfUp);
        } else {
            $afterValue = $beforeValue;
        }

        foreach ([$change, $afterQty] as $quantity) {
            if (! $quantity->isZero()) {
                Money::quantity((string) $quantity->abs(), $decimal);
            }
        }
        foreach ([$afterValue, $afterValue->minus($beforeValue), $variance] as $amount) {
            Money::amount((string) $amount->abs());
        }

        return [
            'qty_before' => (string) $beforeQty, 'counted_qty' => $counted === null ? null : (string) $counted,
            'qty_change' => (string) $change, 'qty_after' => (string) $afterQty, 'value_before' => (string) $beforeValue,
            'value_change' => (string) $afterValue->minus($beforeValue), 'value_after' => (string) $afterValue,
            'valuation_variance' => (string) $variance, 'unit_cost' => (string) $basis,
            'cost_source' => $cost !== null && ! ($change->isNegative() && $counted === null) ? 'explicit' : 'carrying_value',
        ];
    }

    private function quantity(string $value, bool $decimal): BigDecimal
    {
        $absolute = str_starts_with($value, '-') ? substr($value, 1) : $value;
        $quantity = preg_match('/\A0{1,15}(?:\.0{1,3})?\z/', $absolute)
            ? BigDecimal::zero()->toScale(3) : BigDecimal::of(Money::quantity($absolute, $decimal));

        return str_starts_with($value, '-') ? $quantity->negated() : $quantity;
    }

    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HalfUp);
    }
}
