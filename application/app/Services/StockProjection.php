<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class StockProjection
{
    public function append(array $scope, array $movement, ?string $lastKnown): object
    {
        $quantity = BigDecimal::of($movement['qty_after']);
        $value = BigDecimal::of($movement['value_after']);
        foreach ([$movement['value_after'], $movement['value_change'], $movement['valuation_variance']] as $amount) {
            Money::amount((string) BigDecimal::of($amount)->abs());
        }
        if (! $quantity->isZero()) {
            Money::quantity((string) $quantity->abs());
        }

        $average = $this->average($quantity, $value);
        $ledger = $scope + $movement;
        $id = DB::table('stock_ledger')->insertGetId($ledger);
        DB::table('stock_balances')->where($scope)->update([
            'qty' => (string) $quantity, 'inventory_value' => (string) $value, 'avg_cost' => (string) $average,
            'last_known_unit_cost' => $quantity->isPositive() ? (string) $average : $lastKnown,
            'valuation_provisional' => $quantity->isNegative(), 'last_ledger_id' => $id,
        ]);

        return (object) ($ledger + ['id' => $id]);
    }

    public function average(BigDecimal $quantity, BigDecimal $value): BigDecimal
    {
        return $quantity->isPositive() ? $value->dividedBy($quantity, 9, RoundingMode::HalfUp) : BigDecimal::zero()->toScale(9);
    }

    public function lock(array $scope): object
    {
        DB::table('stock_balances')->insertOrIgnore($scope);

        return DB::table('stock_balances')->where($scope)->lockForUpdate()->first();
    }
}
