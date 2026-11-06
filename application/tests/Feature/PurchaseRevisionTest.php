<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\PurchaseCorrectionPreview;
use App\Services\PurchaseRevisionHistory;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PurchaseFixture;
use Tests\TestCase;

class PurchaseRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_remainder_allocates_the_cost_change_without_rewriting_sales(): void
    {
        $preview = app(PurchaseCorrectionPreview::class);
        $before = ['qty' => '10', 'returned_qty' => '0', 'unit_cost' => '100.00'];
        $after = ['qty' => '10', 'unit_cost' => '110.00'];
        $balance = ['qty' => '6', 'value' => '600.00'];
        $result = $preview->line($before, $after, $balance, '6');
        $this->assertSame('60.00', $result['inventory_value_change']);
        $this->assertSame('-40.00', $result['valuation_variance']);
        $this->assertSame('660.00', $result['value_after']);
        $this->assertSame('-100.00', $preview->line($before, $after, $balance, '0')['valuation_variance']);
        foreach ([null, '7'] as $unconfirmed) {
            try {
                $preview->line($before, $after, $balance, $unconfirmed);
                $this->fail('Expected the remaining-unit confirmation to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('confirmed_remaining', $exception->errors());
            }
        }

        $reduction = $preview->line($before, ['qty' => '8', 'unit_cost' => '100.00'], $balance, null);
        $this->assertSame('-200.00', $reduction['inventory_value_change']);
        $this->assertSame('0.00', $reduction['valuation_variance']);
        $this->assertSame('200.00', $preview->settlement(1, 1, '1000', '600', '800', false)['advance_to_original_supplier']);
        $moved = $preview->settlement(1, 2, '1000', '600', '800', false);
        $this->assertSame('800.00', $moved['advance_to_original_supplier']);
        $this->assertSame('600.00', $moved['resulting_payable']);
    }

    public function test_revision_history_keeps_original_and_rejects_stale_or_unauthorized_writes(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $tenant = $branch->tenant_id;
        $owner = User::factory()->create(['tenant_id' => $tenant]);
        $owner->assignRole('Owner');
        $manager = User::factory()->create(['tenant_id' => $tenant]);
        $manager->assignRole('Manager');
        $history = app(PurchaseRevisionHistory::class);
        $purchase = PurchaseFixture::create($owner, $branch->id);
        $original = ['action' => 'create', 'after_snapshot' => ['unit_cost' => '100.00'], 'reason' => 'Initial',
            'financial_effective_at' => now(), 'supplier_balance_delta' => '1000.00'];
        $change = array_replace($original, ['action' => 'update', 'after_snapshot' => ['unit_cost' => '110.00'],
            'reason' => 'Correct supplier cost', 'supplier_balance_delta' => '100.00']);

        DB::transaction(fn () => $history->append($manager, $purchase, 0, $original));
        foreach ([[$manager, 1, 403], [$owner, 0, 409]] as [$actor, $expected, $status]) {
            try {
                DB::transaction(fn () => $history->append($actor, $purchase, $expected, $change));
                $this->fail('Expected revision to be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame($status, $exception->getStatusCode());
            }
        }
        DB::transaction(fn () => $history->append($owner, $purchase, 1, $change));
        $rows = DB::table('purchase_revisions')->orderBy('revision_no')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('100.00', json_decode($rows[0]->after_snapshot, true)['unit_cost']);
        $this->assertSame('100.00', json_decode($rows[1]->before_snapshot, true)['unit_cost']);
        $this->assertSame('110.00', json_decode($rows[1]->after_snapshot, true)['unit_cost']);
    }

    public function test_future_supplier_return_dependencies_block_orphaning_and_excess_removal(): void
    {
        $preview = app(PurchaseCorrectionPreview::class);
        $before = ['qty' => '10', 'returned_qty' => '3', 'unit_cost' => '100.00'];
        $balance = ['qty' => '7', 'value' => '700.00'];
        $valid = $preview->line($before, ['qty' => '8', 'unit_cost' => '100.00'], $balance, null);
        $this->assertSame('5.000', $valid['qty_after']);
        foreach (['0', '2'] as $quantity) {
            try {
                $preview->line($before, ['qty' => $quantity, 'unit_cost' => '100.00'], $balance, null);
                $this->fail('Linked returned quantity must not be orphaned.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('qty', $exception->errors());
            }
        }
        $this->expectException(ValidationException::class);
        $preview->settlement(1, 2, '1000.00', '800.00', '100.00', true);
    }
}
