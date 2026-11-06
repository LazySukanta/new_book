<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\PurchaseTotals;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasePostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchases_post_tax_stock_revision_and_payment_atomically(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $tenant = $branch->tenant_id;
        $owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Purchase', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Books supplier'], $headers)->assertCreated()->json('data.id');
        $unit = DB::table('units')->insertGetId(['tenant_id' => $tenant, 'code' => 'PCS', 'name' => 'Piece']);
        $tax = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'rate_bp' => 1800, 'name' => 'Fixture 18%']);
        $product = Product::forceCreate([
            'tenant_id' => $tenant, 'sku' => 'BOOK1', 'name' => 'Fixture book', 'unit_id' => $unit,
            'tax_rate_id' => $tax, 'tax_inclusive' => true,
        ]);
        $data = [
            'supplier_id' => $supplier, 'supplier_invoice_no' => 'Bill 1', 'supplier_invoice_date' => '2020-01-01',
            'cost_includes_tax' => false, 'tax_type' => 'intra', 'input_tax_recoverable' => true,
            'items' => [['product_id' => $product->id, 'qty' => '10', 'unit_cost' => '10.00']],
        ];
        $request = fn (array $payload, string $key) => $this->postJson('/api/v1/purchases', $payload, $headers + ['Idempotency-Key' => $key]);
        $first = $request($data, 'purchase-1')->assertCreated()->assertJsonPath('data.total', '118.00')
            ->assertJsonPath('data.cgst', '9.00')->assertJsonPath('data.sgst', '9.00')
            ->assertJsonPath('data.items.0.inventory_value', '100.00');
        $request($data, 'purchase-1')->assertCreated()->assertExactJson($first->json());
        $request(array_replace($data, ['supplier_invoice_no' => 'bill   1']), 'duplicate')->assertConflict()
            ->assertJsonPath('code', 'DUPLICATE_SUPPLIER_INVOICE');

        $secondData = array_replace($data, [
            'supplier_invoice_no' => 'Bill 2', 'cost_includes_tax' => true, 'tax_type' => 'inter',
            'input_tax_recoverable' => false, 'items' => [['product_id' => $product->id, 'qty' => '10', 'unit_cost' => '11.80']],
            'payments' => [['mode' => 'bank', 'amount' => '50.00']],
        ]);
        $second = $request($secondData, 'purchase-2')->assertCreated()->assertJsonPath('data.igst', '18.00')
            ->assertJsonPath('data.balance_amount', '68.00')->assertJsonPath('data.items.0.inventory_value', '118.00')->json('data.id');
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'qty' => '20.000', 'inventory_value' => '218.00']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'purchase_price' => '11.80']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $product->id, 'action' => 'product.pricing', 'reason' => 'Supplier purchase cost']);
        $this->assertDatabaseCount('purchase_revisions', 2);
        $this->assertDatabaseHas('payment_allocations', ['purchase_id' => $second, 'amount' => '50.00']);
        $this->getJson('/api/v1/suppliers/'.$supplier.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '186.00');

        $payment = DB::table('payment_allocations')->where('purchase_id', $second)->value('payment_id');
        $this->postJson('/api/v1/suppliers/'.$supplier.'/payments/'.$payment.'/reverse', ['reason' => 'Bank rejected'],
            $headers + ['Idempotency-Key' => 'undo-payment'])->assertCreated();
        $this->assertDatabaseHas('purchases', ['id' => $second, 'balance_amount' => '118.00']);
        $request(array_replace($secondData, ['supplier_invoice_no' => 'Rollback', 'payments' => [['mode' => 'bank', 'amount' => '0']]]),
            'failed-purchase')->assertUnprocessable();
        $this->assertDatabaseCount('purchases', 2);
        $this->assertDatabaseCount('stock_ledger', 2);
        $this->assertDatabaseCount('purchase_revisions', 2);
        $this->artisan('stock:reconcile')->assertSuccessful();
        $laterPayment = $this->postJson('/api/v1/suppliers/'.$supplier.'/payments', ['amount' => '20.00', 'mode' => 'cash'],
            $headers + ['Idempotency-Key' => 'later-payment'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $laterPayment, 'purchase_id' => $first->json('data.id'), 'amount' => '20.00']);
    }

    public function test_discount_and_intra_state_rounding_conserve_paise(): void
    {
        $calculator = app(PurchaseTotals::class);
        $line = ['qty' => '1.000', 'unit_cost' => '1.01', 'rate_bp' => 500];
        $tax = $calculator->calculate([$line], '0.00', false, 'intra', true)['totals'];
        $this->assertSame(['taxable' => '1.01', 'cgst' => '0.03', 'sgst' => '0.02', 'igst' => '0.00', 'total' => '1.06'], $tax);
        $discount = $calculator->calculate([$line, $line], '0.01', false, 'intra', true);
        $this->assertSame(['0.01', '0.00'], array_column($discount['lines'], 'discount_share'));
    }
}
