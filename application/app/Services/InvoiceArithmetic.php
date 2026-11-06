<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class InvoiceArithmetic
{
    public static function allocate(array $amounts, string $discount): array
    {
        $paise = array_map(fn ($amount) => BigDecimal::of($amount)->multipliedBy(100)->toBigInteger(), $amounts);
        $discountPaise = BigDecimal::of($discount)->multipliedBy(100)->toBigInteger();
        $sum = BigInteger::sum(...$paise);
        if ($discountPaise->isGreaterThan($sum)) {
            throw ValidationException::withMessages(['discount_amount' => 'Discount exceeds the available amount.']);
        }
        $shares = array_fill(0, count($amounts), BigInteger::zero());
        if (! $discountPaise->isZero()) {
            $remainders = [];
            foreach ($paise as $index => $amount) {
                [$shares[$index], $remainders[$index]] = $amount->multipliedBy($discountPaise)->quotientAndRemainder($sum);
            }
            $left = $discountPaise->minus(BigInteger::sum(...$shares))->toInt();
            uksort($remainders, fn (int $a, int $b) => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);
            foreach (array_slice(array_keys($remainders), 0, $left) as $index) {
                $shares[$index] = $shares[$index]->plus(1);
            }
        }

        return array_map(fn (BigInteger $share) => BigDecimal::ofUnscaledValue($share, 2), $shares);
    }

    public static function tax(BigDecimal $base, int $rateBp, bool $inclusive, string $taxType): array
    {
        $rate = BigDecimal::ofUnscaledValue(Money::basisPoints($rateBp), 4);
        $taxable = $inclusive ? $base->dividedBy(BigDecimal::one()->plus($rate), 2, RoundingMode::HalfUp) : $base;
        $tax = $inclusive ? $base->minus($taxable) : $taxable->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp);
        $zero = BigDecimal::zero()->toScale(2);
        $cgst = $taxType === 'intra' ? $tax->dividedBy(2, 2, RoundingMode::HalfUp) : $zero;
        $sgst = $taxType === 'intra' ? $tax->minus($cgst) : $zero;
        $igst = $taxType === 'inter' ? $tax : $zero;
        $total = $taxable->plus($tax);

        return compact('taxable', 'cgst', 'sgst', 'igst', 'total');
    }
}
