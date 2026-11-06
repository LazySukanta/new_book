<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class Money
{
    public static function amount(mixed $input): string
    {
        return (string) self::decimal($input, 2, 'amount')->toScale(2);
    }

    public static function quantity(mixed $input, bool $allowDecimal = true): string
    {
        $value = self::decimal($input, 3, 'qty');

        if ($value->isZero() || (! $allowDecimal && ! $value->isEqualTo($value->toScale(0, RoundingMode::Down)))) {
            throw ValidationException::withMessages(['qty' => 'Enter a positive quantity allowed by this unit.']);
        }

        return (string) $value->toScale(3);
    }

    public static function lineAmount(mixed $price, mixed $qty, bool $allowDecimal = true): string
    {
        $amount = BigDecimal::of(self::amount($price))
            ->multipliedBy(self::quantity($qty, $allowDecimal))->toScale(2, RoundingMode::HalfUp);

        return self::amount((string) $amount);
    }

    public static function basisPoints(mixed $input): int
    {
        if (! is_int($input) || $input < 0 || $input > 10000) {
            throw ValidationException::withMessages(['rate_bp' => 'Use an integer from 0 to 10000.']);
        }

        return $input;
    }

    private static function decimal(mixed $input, int $scale, string $field): BigDecimal
    {
        $digits = 18 - $scale;
        if (! is_string($input) || ! preg_match("/^[0-9]{1,$digits}(?:\\.[0-9]{1,$scale})?$/D", $input)) {
            throw ValidationException::withMessages([$field => "Enter a nonnegative decimal string with up to $scale fractional digits."]);
        }

        return BigDecimal::of($input);
    }
}
