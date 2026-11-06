<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\StockPosting;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhysicalCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_reconcile_unknown_and_known_shortages_and_detect_staleness(): void
    {
        [$owner, $branch, $payload, $headers] = $this->shop();
        $product = Product::forceCreate($payload + ['tenant_id' => $owner->tenant_id, 'sku' => 'UNK']);
        $known = Product::forceCreate(array_replace($payload, ['purchase_price' => '40.00']) + ['tenant_id' => $owner->tenant_id, 'sku' => 'KNOWN']);
        $posting = app(StockPosting::class);
        $sale = $posting->issueSale($owner, $branch->id, $product->id, 1, '3');
        $knownSale = $posting->issueSale($owner, $branch->id, $known->id, 2, '3');

        $count = ['reason' => 'Shelf count', 'items' => [
            ['product_id' => $product->id, 'counted_qty' => '47', 'unit_cost' => '40', 'expected_last_ledger_id' => $sale->id],
            ['product_id' => $known->id, 'counted_qty' => '47', 'unit_cost' => '40', 'expected_last_ledger_id' => $knownSale->id],
        ]];
        $first = $this->postJson('/api/v1/stock/count', $count, $headers + ['Idempotency-Key' => 'count-1'])->assertCreated()
            ->assertJsonPath('data.items.0.qty_change', '50.000')->assertJsonPath('data.items.0.value_after', '1880.00')
            ->assertJsonPath('data.items.0.valuation_variance', '-120.00')->assertJsonPath('data.items.1.valuation_variance', '0.00');
        $this->postJson('/api/v1/stock/count', $count, $headers + ['Idempotency-Key' => 'count-1'])->assertCreated()->assertExactJson($first->json());
        $this->postJson('/api/v1/stock/count', $count, $headers + ['Idempotency-Key' => 'count-stale'])->assertConflict()->assertJsonPath('code', 'STALE_STOCK_COUNT');
        $this->postJson('/api/v1/stock/count', $count + ['posted_at' => '2020-01-01'], $headers + ['Idempotency-Key' => 'count-backdate'])
            ->assertUnprocessable()->assertJsonValidationErrors('posted_at');
        $this->assertDatabaseCount('stock_adjustments', 1);
        $this->assertDatabaseHas('stock_ledger', ['id' => $sale->id, 'cost_source' => 'unknown_zero', 'value_change' => '0.00']);

        $this->postJson('/api/v1/stock/adjustments', ['reason' => 'Removal', 'items' => [['product_id' => $product->id, 'qty_change' => '-48']]],
            $headers + ['Idempotency-Key' => 'bad-removal'])->assertUnprocessable();
        $this->postJson('/api/v1/stock/adjustments', ['reason' => 'Missing cost', 'items' => [['product_id' => $product->id, 'qty_change' => '1']]],
            $headers + ['Idempotency-Key' => 'missing-cost'])->assertUnprocessable();
        $this->postJson('/api/v1/stock/adjustments', [
            'reason' => 'Correct unit cost', 'corrects_adjustment_id' => $first->json('data.id'),
            'items' => [['product_id' => $product->id, 'qty_change' => '0', 'unit_cost' => '41']],
        ], $headers + ['Idempotency-Key' => 'cost-correction'])->assertCreated()
            ->assertJsonPath('data.items.0.qty_change', '0.000')->assertJsonPath('data.items.0.value_change', '47.00');
        $this->getJson('/api/v1/stock/adjustments', $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_opening_stock_posts_with_product_creation_and_csv_commit(): void
    {
        [, $branch, $payload, $headers] = $this->shop();
        $created = $this->postJson('/api/v1/products', $payload + ['opening_qty' => '5', 'opening_unit_cost' => '10'],
            $headers + ['Idempotency-Key' => 'opening-form'])->assertCreated()
            ->assertJsonPath('data.opening_stock.items.0.value_after', '50.00')->json('data.id');
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $branch->id, 'product_id' => $created, 'qty' => '5.000']);
        $csv = "name,sku,unit,gst_rate,retail_price,wholesale_price,opening_qty,opening_unit_cost\nPencils,PN1,PCS,0,15,12,4,8\n";
        $upload = fn (string $key, string $query = '') => $this->post('/api/v1/products/import'.$query, [
            'file' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv),
        ], $headers + ['Idempotency-Key' => $key]);
        $upload('csv-stock', '?dry_run=1')->assertOk()->assertJsonPath('data.written', 0);
        $this->assertDatabaseCount('products', 1);
        $upload('csv-stock')->assertOk()->assertJsonPath('data.written', 1);
        $upload('csv-stock')->assertOk();
        $upload('csv-new-key')->assertUnprocessable()->assertJsonValidationErrors('rows.2.opening_qty');
        $this->assertDatabaseCount('stock_ledger', 2);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $branch->id, 'qty' => '4.000', 'inventory_value' => '32.00']);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    private function shop(): array
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Counts', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Branch-Id' => $branch->id];
        $unit = DB::table('units')->insertGetId(['tenant_id' => $branch->tenant_id, 'name' => 'Piece', 'code' => 'PCS']);
        $tax = DB::table('tax_rates')->insertGetId(['tenant_id' => $branch->tenant_id, 'name' => 'Fixture', 'rate_bp' => 0]);
        $product = ['name' => 'Study pack', 'unit_id' => $unit, 'tax_rate_id' => $tax, 'tax_inclusive' => true,
            'retail_price' => '50.00', 'wholesale_price' => '45.00'];

        return [$owner, $branch, $product, $headers];
    }
}
