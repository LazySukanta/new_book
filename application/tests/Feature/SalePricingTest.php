<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_resolves_prices_discounts_supply_and_fingerprints_without_posting(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $branch->forceFill(['state_code' => '36', 'gstin' => '36ABCDE1234F1Z5'])->save();
        $tenant = $branch->tenant_id;
        $owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Preview test', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $unit = DB::table('units')->insertGetId(['tenant_id' => $tenant, 'code' => 'PCS', 'name' => 'Piece']);
        $tax = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'rate_bp' => 1800, 'name' => '18%']);
        $zero = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'rate_bp' => 0, 'name' => 'Zero']);
        $a = Product::forceCreate(['tenant_id' => $tenant, 'sku' => 'A', 'name' => 'A', 'unit_id' => $unit, 'tax_rate_id' => $tax,
            'retail_price' => '118.00', 'wholesale_price' => '112.00', 'tax_inclusive' => true]);
        $b = Product::forceCreate(['tenant_id' => $tenant, 'sku' => 'B', 'name' => 'B', 'unit_id' => $unit, 'tax_rate_id' => $tax,
            'retail_price' => '118.00', 'wholesale_price' => '112.00', 'tax_inclusive' => false]);
        DB::table('product_price_slabs')->insert([
            ['product_id' => $a->id, 'min_qty' => '2', 'price' => '106.20'],
            ['product_id' => $a->id, 'min_qty' => '3', 'price' => '94.40'],
            ['product_id' => $a->id, 'min_qty' => '5', 'price' => '82.60'],
        ]);
        $post = fn (array $data) => $this->postJson('/api/v1/sales/calculate', $data, $headers);
        $cart = ['sale_type' => 'retail', 'items' => [['product_id' => $a->id, 'qty' => '2']]];
        $fingerprint = $post($cart)->assertOk()->assertJsonPath('data.document_type', 'tax_invoice')
            ->assertJsonPath('data.totals.taxable', '200.00')->assertJsonPath('data.totals.cgst', '18.00')
            ->assertJsonPath('data.totals.sgst', '18.00')->assertJsonPath('data.totals.total', '236.00')->json('data.pricing_fingerprint');
        $a->forceFill(['name' => 'Renamed', 'wholesale_price' => '110.00'])->save();
        $post(['items' => [['qty' => '2.000', 'product_id' => $a->id]], 'sale_type' => 'retail'])
            ->assertOk()->assertJsonPath('data.pricing_fingerprint', $fingerprint);
        $post($cart + ['place_of_supply_override' => '27'])->assertOk()->assertJsonPath('data.totals.igst', '36.00')
            ->assertJsonPath('data.totals.cgst', '0.00');
        $post(array_replace($cart, ['sale_type' => 'wholesale']))->assertOk()->assertJsonPath('data.items.0.unit_price', '106.20')
            ->assertJsonPath('data.items.0.price_source', 'wholesale_slab');
        $post(['sale_type' => 'wholesale', 'items' => [['product_id' => $a->id, 'qty' => '4']]])
            ->assertOk()->assertJsonPath('data.items.0.unit_price', '94.40');
        $mixed = ['items' => [['product_id' => $a->id, 'qty' => '1'], ['product_id' => $b->id, 'qty' => '1']],
            'bill_discount' => ['type' => 'amount', 'value' => '0.01']];
        $post($mixed)->assertOk()->assertJsonPath('data.items.0.bill_discount_share', '0.01')
            ->assertJsonPath('data.items.1.bill_discount_share', '0.00')->assertJsonPath('data.items.1.line_total', '139.24');
        $post(array_replace($cart, ['bill_discount' => ['type' => 'amount', 'value' => '236.01']]))->assertUnprocessable();
        $override = ['items' => [['product_id' => $a->id, 'qty' => '1', 'unit_price' => '59.00', 'discount' => ['type' => 'percent', 'value_bp' => 1000]]]];
        $post($override)->assertOk()->assertJsonPath('data.items.0.price_source', 'override')->assertJsonPath('data.totals.total', '53.10');
        $a->forceFill(['retail_price' => '119.00'])->save();
        $this->assertNotSame($fingerprint, $post($cart)->assertOk()->json('data.pricing_fingerprint'));
        $b->forceFill(['tax_rate_id' => $zero])->save();
        $post($mixed)->assertUnprocessable()->assertJsonValidationErrors('items');
        $post(['items' => [['product_id' => $b->id, 'qty' => '1']]])->assertOk()->assertJsonPath('data.document_type', 'bill_of_supply');
        DB::table('shop_settings')->updateOrInsert(['tenant_id' => $tenant], ['name' => 'Preview shop', 'gst_registration_type' => 'composition']);
        $post($cart)->assertOk()->assertJsonPath('data.document_type', 'bill_of_supply')->assertJsonPath('data.totals.cgst', '0.00');
        $post($cart + ['place_of_supply_override' => '27'])->assertUnprocessable();
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['gst_registration_type' => 'unregistered']);
        $post($cart)->assertOk()->assertJsonPath('data.document_type', 'commercial_bill')->assertJsonPath('data.totals.total', '238.00');
        $owner->syncRoles(['Cashier']);
        $this->app['auth']->forgetGuards();
        $post($override)->assertForbidden();
        $post($cart + ['place_of_supply_override' => '27'])->assertForbidden();
        $post($cart + ['bill_discount' => ['type' => 'percent', 'value_bp' => 501]])->assertUnprocessable();
        $post($cart + ['bill_discount' => ['type' => 'percent', 'value_bp' => 500]])->assertOk();
        $this->assertDatabaseCount('stock_ledger', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }
}
