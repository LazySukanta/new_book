<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class PurchaseTotals
{
    public function calculate(array $items, string $discount, bool $inclusive, string $taxType, bool $recoverable): array
    {
        $gross = array_map(fn (array $item) => Money::lineAmount($item['unit_cost'], $item['qty']), $items);
        $shares = InvoiceArithmetic::allocate($gross, Money::amount($discount));

        $totals = array_fill_keys(['taxable', 'cgst', 'sgst', 'igst', 'total'], BigDecimal::zero()->toScale(2));
        $lines = [];
        foreach ($items as $index => $item) {
            $base = BigDecimal::of($gross[$index])->minus($shares[$index]);
            ['taxable' => $taxable, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => $total] =
                InvoiceArithmetic::tax($base, $item['rate_bp'], $inclusive, $taxType);
            foreach (compact('taxable', 'cgst', 'sgst', 'igst', 'total') as $key => $value) {
                $totals[$key] = $totals[$key]->plus($value);
            }
            $inventory = $recoverable ? $taxable : $total;
            $inventoryCost = (string) $inventory->dividedBy($item['qty'], 9, RoundingMode::HalfUp);
            if (! preg_match('/\A[0-9]{1,15}\.[0-9]{9}\z/', $inventoryCost)) {
                throw ValidationException::withMessages(["items.$index.unit_cost" => 'The landed unit cost is outside the supported range.']);
            }
            $lines[] = $item + [
                'discount_share' => (string) $shares[$index],
                'taxable' => (string) $taxable, 'cgst' => (string) $cgst, 'sgst' => (string) $sgst, 'igst' => (string) $igst,
                'line_total' => (string) $total, 'inventory_value' => (string) $inventory,
                'inventory_unit_cost' => $inventoryCost,
            ];
        }

        return [
            'totals' => array_map(fn (BigDecimal $value) => Money::amount((string) $value), $totals),
            'lines' => $lines,
        ];
    }
}
