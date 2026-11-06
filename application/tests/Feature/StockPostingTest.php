<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\StockPosting;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\PurchaseFixture;
use Tests\TestCase;

class StockPostingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_receipts_issues_shortage_settlement_and_rollback_reconcile(): void
    {
        [$owner, $branch, $product] = $this->stockFixture();
        $posting = app(StockPosting::class);
        $unknown = $posting->issueSale($owner, $branch, $product, 1, '3');
        $this->assertSame('unknown_zero', $unknown->cost_source);
        $this->assertSame('-3.000', $unknown->qty_after);
        $this->assertSame('0.00', $unknown->value_after);
        $partial = $posting->receive($owner, $branch, $product, 1, '1', '40');
        $this->assertSame('-2.000', $partial->qty_after);
        $this->assertSame('-40.00', $partial->valuation_variance);
        $settled = $posting->receive($owner, $branch, $product, 2, '4', '40');
        $this->assertSame('2.000', $settled->qty_after);
        $this->assertSame('80.00', $settled->value_after);
        $this->assertSame('-80.00', $settled->valuation_variance);

        $mixed = $posting->issueSale($owner, $branch, $product, 2, '3');
        $this->assertSame('1.000', $mixed->estimated_qty);
        $this->assertSame('last_known', $mixed->cost_source);
        $this->assertSame('-120.00', $mixed->value_change);
        $this->assertSame('-40.00', $mixed->value_after);
        $newReceipt = $posting->receive($owner, $branch, $product, 3, '2', '50');
        $this->assertSame('50.00', $newReceipt->value_after);
        $this->assertSame('-10.00', $newReceipt->valuation_variance);
        $this->assertDatabaseHas('stock_ledger', ['id' => $unknown->id, 'value_change' => '0.00', 'cost_source' => 'unknown_zero']);

        $posting->issueSale($owner, $branch, $product, 3, '1');
        $posting->receive($owner, $branch, $product, 4, '3', '0.335');
        $posting->issueSale($owner, $branch, $product, 4, '1');
        $depletion = $posting->issueSale($owner, $branch, $product, 5, '2');
        $this->assertSame('0.000', $depletion->qty_after);
        $this->assertSame('0.00', $depletion->value_after);

        try {
            DB::transaction(function () use ($posting, $owner, $branch, $product) {
                $posting->receive($owner, $branch, $product, 5, '1', '5');
                throw new RuntimeException('Fixture rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture rollback', $exception->getMessage());
        }
        $this->assertDatabaseMissing('stock_ledger', ['purchase_item_id' => 5]);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product, 'qty' => '0.000', 'inventory_value' => '0.00']);
        $this->artisan('stock:reconcile')->assertSuccessful();
        DB::table('stock_balances')->where('product_id', $product)->update(['inventory_value' => '1.00']);
        $this->artisan('stock:reconcile')->assertFailed();
    }

    public function test_overlapping_last_unit_sales_cannot_both_issue_when_negative_is_disabled(): void
    {
        [$owner, $branch, $product] = $this->stockFixture();
        DB::table('shop_settings')->insert(['tenant_id' => $owner->tenant_id, 'name' => 'Fixture', 'allow_negative_stock' => false]);
        $posting = app(StockPosting::class);
        $posting->receive($owner, $branch, $product, 1, '1', '40');
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $user = App\Models\User::findOrFail($argv[1]);
        echo "ready\n";
        try {
            $app->make(App\Services\StockPosting::class)->issueSale($user, (int) $argv[2], (int) $argv[3], 2, '1');
        } catch (Illuminate\Validation\ValidationException $exception) {
            echo $exception->errors()['qty'][0];
            exit(0);
        }
        exit(1);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, (string) $owner->id, (string) $branch, (string) $product], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'khatapos_test', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        ], timeout: 15);
        try {
            DB::transaction(function () use ($posting, $owner, $branch, $product, $process) {
                $posting->issueSale($owner, $branch, $product, 1, '1');
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $process->getErrorOutput());
                usleep(200_000);
                $this->assertTrue($process->isRunning());
            });
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertStringContainsString('Insufficient stock', $process->getOutput());
            $this->assertDatabaseCount('stock_ledger', 2);
            $this->artisan('stock:reconcile')->assertSuccessful();
        } finally {
            $process->stop();
        }
    }

    private function stockFixture(): array
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $tenant = $branch->tenant_id;
        $owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true]);
        $unit = DB::table('units')->insertGetId(['tenant_id' => $tenant, 'code' => 'PCS', 'name' => 'Piece']);
        $tax = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'rate_bp' => 0, 'name' => 'Fixture']);
        $product = Product::forceCreate([
            'tenant_id' => $tenant, 'sku' => 'ST1', 'name' => 'Study card', 'unit_id' => $unit,
            'tax_rate_id' => $tax, 'tax_inclusive' => true,
        ]);
        for ($index = 0; $index < 5; $index++) {
            PurchaseFixture::create($owner, $branch->id, $product->id);
        }

        return [$owner, $branch->id, $product->id];
    }
}
