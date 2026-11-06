<?php

namespace Tests\Feature;

use App\Http\ApiResponse;
use App\Models\User;
use App\Services\Money;
use App\Services\ShopCalendar;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FoundationConventionsTest extends TestCase
{
    public function test_exact_decimal_rounding_and_bounds(): void
    {
        $this->assertSame('1.54', Money::lineAmount('12.35', '0.125'));
        $this->assertSame('0.01', Money::lineAmount('0.01', '0.500'));
        $this->assertSame('1.000', Money::quantity('1.000', false));
        $this->assertSame(1800, Money::basisPoints(1800));
        foreach ([fn () => Money::amount(1.1), fn () => Money::amount('0.001'),
            fn () => Money::quantity('0.100', false), fn () => Money::quantity('0'),
            fn () => Money::lineAmount('9999999999999999.99', '2'), fn () => Money::basisPoints(10001)] as $invalid) {
            try {
                $invalid();
                $this->fail('Expected invalid decimal or rate');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_financial_year_changes_at_indian_midnight(): void
    {
        $this->assertSame('25-26', ShopCalendar::financialYear(new DateTimeImmutable('2026-03-31T18:29:59Z')));
        $instant = new DateTimeImmutable('2026-03-31T18:30:00Z');
        $this->assertSame('2026-04-01', ShopCalendar::localDate($instant));
        $this->assertSame('26-27', ShopCalendar::financialYear($instant));
    }

    public function test_http_contract_and_sort_whitelist(): void
    {
        Route::get('/api/v1/test-list', fn (Request $r) => ApiResponse::page($r, User::query(), ['name']));
        Route::get('/api/v1/test-conflict', fn () => ApiResponse::conflict('PRICE_CHANGED', 'Review updated pricing.'));
        $this->getJson('/api/v1/test-list')->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->getJson('/api/v1/test-list?sort=password&per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['sort', 'per_page']);
        $this->getJson('/api/v1/test-conflict')->assertConflict()->assertJsonPath('code', 'PRICE_CHANGED');
    }
}
