<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogueBalances;
use App\Services\CataloguePrice;
use App\Services\DeviceEnrollment;
use App\Services\StockPosting;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\PurchaseFixture;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use DatabaseMigrations;

    public function test_bookstore_catalogue_pricing_and_zero_balance_initialization(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Catalogue', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $unit = $this->postJson('/api/v1/units', ['name' => 'Piece', 'code' => 'PCS'], $headers)->assertCreated()->json('data.id');
        $tax = $this->postJson('/api/v1/tax-rates', ['name' => 'Fixture', 'rate_bp' => 0], $headers)->assertCreated()->json('data.id');
        $category = $this->postJson('/api/v1/categories', ['name' => 'Study accessories'], $headers)->assertCreated()->json('data.id');
        $payload = [
            'name' => 'Study notebook', 'sku' => 'NB1', 'barcode' => '001234', 'category_id' => $category,
            'unit_id' => $unit, 'tax_rate_id' => $tax, 'tax_inclusive' => true, 'retail_price' => '120.00',
            'wholesale_price' => '100.00', 'slabs' => [['min_qty' => '10.000', 'price' => '90.00']],
        ];
        $this->postJson('/api/v1/products', array_replace($payload, ['reorder_level' => '1.500']), $headers)->assertUnprocessable();
        $id = $this->postJson('/api/v1/products', $payload, $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/products', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors(['sku', 'barcode']);
        $this->getJson('/api/v1/products?search=Study', $headers)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/products/lookup?code=001234', $headers)->assertOk()->assertJsonPath('data.stock_qty', '0.000');
        $this->getJson('/api/v1/products?low_stock=1', $headers)->assertOk()->assertJsonPath('data.0.id', $id);
        $product = Product::findOrFail($id);
        $price = app(CataloguePrice::class);
        $this->assertSame('100.00', $price->resolve($product, '9', true));
        $this->assertSame('90.00', $price->resolve($product, '10', true));
        $this->assertSame('120.00', $price->resolve($product, '10', false));
        DB::statement('CREATE TEMPORARY TABLE catalogue_document_fixture (snapshot JSON)');
        DB::table('catalogue_document_fixture')->insert(['snapshot' => '{"price":"120.00"}']);
        $this->patchJson('/api/v1/products/'.$id, ['retail_price' => '125.00'], $headers)->assertOk();
        $this->assertSame(['price' => '120.00'], json_decode(DB::table('catalogue_document_fixture')->value('snapshot'), true));
        $this->getJson('/api/v1/products/'.$id.'/price-history', $headers)->assertOk()
            ->assertJsonPath('data.0.before_json.retail_price', '120.00')->assertJsonPath('data.0.after_json.retail_price', '125.00');
        $second = $this->postJson('/api/v1/branches', ['code' => 'S2', 'name' => 'Second'], $headers)->assertCreated()->json('data.id');
        $this->assertDatabaseCount('stock_balances', 2);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $second, 'product_id' => $id, 'qty' => '0.000', 'inventory_value' => '0.00']);
        $this->verifyOverlappingBalanceInitialization(Branch::findOrFail($second), $id);

        $this->assertDatabaseCount('stock_ledger', 0);
        app(StockPosting::class)->receive($owner, $branch->id, $id, PurchaseFixture::create($owner, $branch->id, $id), '1', '25.00');
        $this->getJson('/api/v1/stock', $headers)->assertOk()->assertJsonPath('data.0.qty', '1.000');
        $this->getJson('/api/v1/stock/ledger?product_id='.$id, $headers)->assertOk()
            ->assertJsonPath('data.0.value_change', '25.00');
        $this->getJson('/api/v1/stock', array_replace($headers, ['X-Branch-Id' => $second]))->assertNotFound();
        $this->deleteJson('/api/v1/products/'.$id, [], $headers)->assertConflict()->assertJsonPath('code', 'PRODUCT_HAS_HISTORY');
    }

    private function verifyOverlappingBalanceInitialization(Branch $branch, int $product): void
    {
        DB::table('stock_balances')->where(['branch_id' => $branch->id, 'product_id' => $product])->delete();
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $branch = App\Models\Branch::findOrFail($argv[1]);
        echo "ready\n";
        App\Services\CatalogueBalances::forBranch($branch);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, (string) $branch->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'khatapos_test', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        ], timeout: 15);
        try {
            DB::transaction(function () use ($branch, $process) {
                CatalogueBalances::forBranch($branch);
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $process->getErrorOutput());
                usleep(200_000);
                $this->assertTrue($process->isRunning());
            });
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertDatabaseCount('stock_balances', 2);
        } finally {
            $process->stop();
        }
    }
}
