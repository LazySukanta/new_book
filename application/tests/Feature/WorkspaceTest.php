<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use Database\Seeders\ShopAccessSeeder;
use Database\Seeders\TradingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Tests\TestCase;

final class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disposable_trading_fixture_can_be_seeded_again_without_posting_documents(): void
    {
        $this->seed(TradingDemoSeeder::class);
        $this->seed(TradingDemoSeeder::class);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('document_series', 2);
        $this->assertDatabaseCount('stock_ledger', 0);
        $this->assertDatabaseCount('issued_documents', 0);
    }

    public function test_party_workspace_posts_openings_and_evidence_with_branch_and_role_checks(): void
    {
        $this->withoutVite();
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $tenant = $branch->tenant_id;
        $owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $cashier = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true]);
        $cashier->assignRole('Cashier');
        $device = app(DeviceEnrollment::class)->register($owner, 'Ledger browser', [$branch->id])['device'];
        $this->actingAs($owner)->withSession(['device_id' => $device->id, 'tenant_id' => $tenant]);
        $headers = ['X-Branch-Id' => (string) $branch->id];
        $base = '/workspace/party-data';
        $this->get('/workspace/customers')->assertOk()->assertSee('Customer directory');
        $this->get('/workspace/suppliers')->assertOk()->assertSee('Supplier directory');
        $customer = $this->postJson($base.'/customers', ['name' => 'Ledger buyer'])->assertCreated()->json('data.id');
        $supplier = $this->postJson($base.'/suppliers', ['name' => 'Ledger supplier'])->assertCreated()->json('data.id');
        $customerUrl = $base.'/customers/'.$customer;
        $supplierUrl = $base.'/suppliers/'.$supplier;
        $this->getJson($customerUrl.'/ledger')->assertForbidden();
        $this->getJson($customerUrl.'/ledger', $headers)->assertOk()->assertJsonPath('meta.opening_status', 'not_entered');
        $date = now()->subDays(5)->toIso8601String();
        $opening = ['entry_type' => 'due', 'amount' => '100.00', 'effective_at' => $date, 'reason' => 'Prior statement'];
        $id = $this->postJson($customerUrl.'/opening-entries', $opening, $headers + ['Idempotency-Key' => 'workspace-opening'])
            ->assertCreated()->json('data.id');
        $receipt = ['amount' => '25.00', 'mode' => 'cash', 'historical_entry' => true, 'reason' => 'Recorded evidence',
            'paid_at' => now()->subDays(6)->toIso8601String(), 'included_in_opening_entry_id' => $id];
        $this->postJson($customerUrl.'/receipts/preview', $receipt, $headers)->assertOk()->assertJsonPath('data.balance_delta', '0.00');
        $receiptId = $this->postJson($customerUrl.'/receipts', $receipt, $headers + ['Idempotency-Key' => 'workspace-evidence'])
            ->assertCreated()->json('data.id');
        $this->getJson($customerUrl.'/entries/receipt/'.$receiptId, $headers)->assertOk()
            ->assertJsonPath('data.record.included_in_opening_entry_id', $id)->assertJsonCount(0, 'data.allocations');
        $this->getJson($customerUrl.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '100.00')
            ->assertJsonPath('data.0.running_balance', '100.00')->assertJsonPath('data.1.signed_amount', '0.00');
        $this->postJson($customerUrl.'/receipts/'.$receiptId.'/reverse', ['reason' => 'Correct evidence'],
            $headers + ['Idempotency-Key' => 'workspace-reverse-evidence'])->assertCreated()->assertJsonPath('data.reversal_of_id', $receiptId);
        $this->postJson($supplierUrl.'/opening-status/confirm-zero', ['reason' => 'Reconciled', 'effective_at' => $date],
            $headers + ['Idempotency-Key' => 'workspace-zero'])->assertCreated();
        $this->getJson($supplierUrl.'/ledger', $headers)->assertOk()->assertJsonPath('meta.opening_status', 'confirmed_zero')
            ->assertJsonPath('meta.balance', '0.00')->assertJsonCount(0, 'data');
        $this->postJson($supplierUrl.'/opening-entries', array_replace($opening, ['effective_at' => now()->addDay()->toIso8601String()]),
            $headers + ['Idempotency-Key' => 'workspace-future'])->assertUnprocessable();
        $this->actingAs($cashier)->get('/workspace/suppliers')->assertForbidden();
        $this->postJson($customerUrl.'/opening-entries', $opening, $headers + ['Idempotency-Key' => 'workspace-cashier-denied'])->assertForbidden();
        $this->postJson($customerUrl.'/receipts/preview', $receipt, $headers)->assertForbidden();
        $this->get('/workspace/reports')->assertOk()->assertSee('My daily sales')->assertDontSee('Branch dashboard');
        $this->getJson('/workspace/report-data/daily-sales', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/workspace/report-data/dashboard', $headers)->assertForbidden();
        $this->getJson('/workspace/report-data/profit', $headers)->assertForbidden();
    }

    public function test_pos_holds_reprices_checks_out_and_returns_in_paired_workspace(): void
    {
        $this->withoutVite();
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $branch->forceFill(['state_code' => '36'])->save();
        $tenant = $branch->tenant_id;
        $owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $device = app(DeviceEnrollment::class)->register($owner, 'POS browser', [$branch->id])['device'];
        $this->actingAs($owner)->withSession(['device_id' => $device->id, 'tenant_id' => $tenant]);
        DB::table('shop_settings')->insert(['tenant_id' => $tenant, 'name' => 'POS shop', 'gst_registration_type' => 'unregistered']);
        $unit = DB::table('units')->insertGetId(['tenant_id' => $tenant, 'code' => 'PCS', 'name' => 'Piece']);
        $rate = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'name' => 'Zero', 'rate_bp' => 0]);
        $product = Product::forceCreate(['tenant_id' => $tenant, 'sku' => 'PEN', 'barcode' => '101', 'name' => 'Pen',
            'unit_id' => $unit, 'tax_rate_id' => $rate, 'retail_price' => '10.00', 'wholesale_price' => '8.00', 'purchase_price' => '4.00',
            'tax_inclusive' => false]);
        $headers = ['X-Branch-Id' => (string) $branch->id];
        $cart = ['items' => [['product_id' => $product->id, 'qty' => '2']], 'note' => 'Retain this comment'];
        $this->get('/workspace/pos')->assertOk()->assertSee('Point of sale');
        $this->getJson('/workspace/pos/lookup?code=101', $headers)->assertOk()->assertJsonPath('data.id', $product->id);
        $this->postJson('/workspace/pos/calculate', $cart)->assertForbidden();
        $before = $this->postJson('/workspace/pos/calculate', $cart, $headers)->assertOk()
            ->assertJsonPath('data.totals.total', '20.00')->json('data.pricing_fingerprint');
        $hold = $this->postJson('/workspace/pos/held', $cart + ['status' => 'completed', 'acknowledged_pricing_fingerprint' => $before],
            $headers + ['Idempotency-Key' => 'initial-pos-hold'])->assertCreated()->assertJsonPath('data.status', 'held')->json('data.id');
        $product->forceFill(['retail_price' => '20.00'])->save();
        $this->getJson('/workspace/pos/held', $headers)->assertOk()->assertJsonPath('data.0.id', $hold)
            ->assertJsonPath('data.0.pricing.pricing_fingerprint', $before);
        $this->postJson('/workspace/pos/held', $cart + ['held_sale_id' => $hold, 'acknowledged_pricing_fingerprint' => $before],
            $headers + ['Idempotency-Key' => 'stale-pos-hold'])->assertConflict()->assertJsonPath('code', 'PRICE_CHANGED');
        $after = $this->postJson('/workspace/pos/calculate', $cart, $headers)->assertOk()
            ->assertJsonPath('data.totals.total', '40.00')->json('data.pricing_fingerprint');
        $this->postJson('/workspace/pos/held', $cart + ['held_sale_id' => $hold, 'acknowledged_pricing_fingerprint' => $after],
            $headers + ['Idempotency-Key' => 'updated-pos-hold'])->assertOk()->assertJsonPath('data.id', $hold)
            ->assertJsonPath('data.cart.note', 'Retain this comment');
        $this->deleteJson('/workspace/pos/held/'.$hold, ['reason' => 'Customer changed their mind'], $headers + ['Idempotency-Key' => 'discard-pos-hold'])
            ->assertOk()->assertJsonPath('data.status', 'discarded');
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('issued_documents', 0);
        $this->assertDatabaseCount('stock_ledger', 0);
        $this->assertDatabaseCount('payments', 0);

        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['receipt_width_mm' => 58]);
        $supplier = $this->postJson('/workspace/party-data/suppliers', ['name' => 'Trading day supplier'])
            ->assertCreated()->json('data.id');
        $purchaseForm = [
            'branch_id' => $branch->id, 'key' => (string) \Illuminate\Support\Str::uuid(),
            'supplier_id' => $supplier, 'supplier_invoice_no' => 'R1 trading day',
            'supplier_invoice_date' => now('Asia/Kolkata')->toDateString(),
            'cost_includes_tax' => 0, 'tax_type' => 'intra', 'input_tax_recoverable' => 0,
            'items' => [['product_id' => $product->id, 'qty' => '5', 'unit_cost' => '4.00', 'rate_bp' => 0]],
        ];
        $this->post('/workspace/purchases', $purchaseForm)->assertRedirect()->assertSessionHasNoErrors();
        $purchase = DB::table('purchases')->where('supplier_invoice_no', 'R1 trading day')->value('id');
        $this->assertNotNull($purchase);
        foreach (['commercial_bill' => 'A', 'credit_note' => 'X'] as $type => $prefix) {
            DB::table('document_series')->insert([
                'tenant_id' => $tenant, 'branch_id' => $branch->id, 'financial_year' => ShopCalendar::financialYear(now()),
                'document_type' => $type, 'owning_channel' => 'web', 'prefix' => $prefix, 'rollover_order' => 1,
                'created_by' => $owner->id,
            ]);
        }
        $checkout = $cart + ['acknowledged_pricing_fingerprint' => $after, 'payments' => [
            ['mode' => 'cash', 'amount' => '20.00'], ['mode' => 'upi', 'amount' => '20.00'],
        ]];
        $this->postJson('/workspace/pos/checkout', array_replace($checkout, ['payments' => []]),
            $headers + ['Idempotency-Key' => 'underpaid-pos'])->assertUnprocessable();
        $checkoutHeaders = $headers + ['Idempotency-Key' => 'split-payment-pos'];
        $issued = $this->postJson('/workspace/pos/checkout', $checkout + ['status' => 'held'], $checkoutHeaders)->assertCreated()
            ->assertJsonPath('data.paid_amount', '40.00')->assertJsonPath('data.status', 'completed');
        $sale = $issued->json('data.id');
        $this->postJson('/workspace/pos/checkout', $checkout, $checkoutHeaders)->assertCreated()->assertExactJson($issued->json());
        $this->assertDatabaseCount('issued_documents', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->get('/workspace/reports')->assertOk()->assertSee('Report filters');
        $this->getJson('/workspace/report-data/dashboard')->assertForbidden();
        $this->getJson('/workspace/report-data/dashboard', $headers)->assertOk()
            ->assertJsonPath('meta.branch_id', $branch->id)->assertJsonPath('data.sales.total', '40.00');
        $this->getJson('/workspace/report-data/daily-sales', $headers)->assertOk()
            ->assertJsonPath('data.0.net', '40.00')->assertJsonPath('data.0.collected_by_mode.upi', '20.00');
        $this->getJson('/workspace/report-data/daily-sales?from=2020-01-01&to=2020-01-01', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/workspace/report-data/daily-sales?from=2020-01-02&to=2020-01-01', $headers)->assertUnprocessable();
        $this->getJson('/workspace/report-data/stock-valuation', $headers)->assertOk()->assertJsonPath('meta.basis', 'current');
        $page = '/workspace/pos/invoices/'.$branch->id.'/'.$sale;
        $this->get($page)->assertOk()->assertSee($issued->json('data.invoice_no'))
            ->assertSee('Retain this comment')->assertSee('Print 58 mm receipt');
        $this->get('/workspace/pos/invoices/'.$branch->id)->assertOk()->assertSee($issued->json('data.invoice_no'));
        $product->forceFill(['retail_price' => '30.00'])->save();
        $returned = $this->postJson('/workspace/pos/sales/'.$sale.'/returns', [
            'items' => [['sale_item_id' => $issued->json('data.items.0.id'), 'qty' => '1']],
            'reason' => 'One unit returned', 'refund_mode' => 'cash',
        ], $headers + ['Idempotency-Key' => 'return-pos'])->assertCreated()->assertJsonPath('data.totals.total', '20.00');
        $note = $returned->json('data.id');
        $notePage = '/workspace/pos/invoices/'.$branch->id.'/credit-note/'.$note;
        $this->get($notePage)->assertOk()->assertSee('CREDIT NOTE')->assertSee($issued->json('data.invoice_no'));
        $this->postJson('/workspace/pos/credit-notes/'.$note.'/cancel', [
            'reason' => 'Return reversed', 'refund_recovered' => true,
        ], $headers + ['Idempotency-Key' => 'reverse-pos'])->assertOk();
        $this->get($notePage)->assertOk()->assertSee('CANCELLED');

        $customer = $this->postJson('/workspace/party-data/customers', ['name' => 'Trading day customer', 'credit_limit' => '100.00'])
            ->assertCreated()->json('data.id');
        $creditCart = ['customer_id' => $customer, 'items' => [['product_id' => $product->id, 'qty' => '2']]];
        $fingerprint = $this->postJson('/workspace/pos/calculate', $creditCart, $headers)->assertOk()->json('data.pricing_fingerprint');
        $creditSale = $this->postJson('/workspace/pos/checkout', $creditCart + ['acknowledged_pricing_fingerprint' => $fingerprint],
            $headers + ['Idempotency-Key' => 'trading-credit-sale'])->assertCreated()->assertJsonPath('data.balance_amount', '60.00');
        $this->postJson('/workspace/pos/sales/'.$creditSale->json('data.id').'/returns', [
            'items' => [['sale_item_id' => $creditSale->json('data.items.0.id'), 'qty' => '1']],
            'reason' => 'Trading day return',
        ], $headers + ['Idempotency-Key' => 'trading-credit-return'])->assertCreated()->assertJsonPath('data.credit_adjusted', '30.00');
        $this->postJson('/workspace/party-data/customers/'.$customer.'/receipts', ['amount' => '10.00', 'mode' => 'cash'],
            $headers + ['Idempotency-Key' => 'trading-collection'])->assertCreated();
        $detail = '/workspace/purchases/'.$purchase;
        $this->from($detail.'/correct')->post($detail.'/preview', [
            'branch_id' => $branch->id, 'action' => 'update', 'expected_revision' => 1, 'reason' => 'Supplier counted one extra',
            'items' => [['product_id' => $product->id, 'qty' => '6', 'unit_cost' => '4.00', 'rate_bp' => 0]],
        ])->assertRedirect($detail.'/correct')->assertSessionHasNoErrors();
        $this->post($detail.'/confirm', ['preview_token' => session('purchase.token')])->assertRedirect($detail)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'qty' => '3.000', 'inventory_value' => '12.00']);
        $this->getJson('/workspace/party-data/customers/'.$customer.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '20.00');
        $this->getJson('/workspace/party-data/suppliers/'.$supplier.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '24.00');
        $this->getJson('/workspace/report-data/daily-sales', $headers)->assertOk()
            ->assertJsonPath('data.0.net', '70.00')->assertJsonPath('data.0.collected_by_mode.cash', '30.00')
            ->assertJsonPath('data.0.collected_by_mode.upi', '20.00');
    }

    public function test_workspace_requires_a_paired_session_and_remembers_an_authorized_branch(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('Shop workspace');
        $this->get('/workspace')->assertRedirect('/');

        $this->seed(ShopAccessSeeder::class);
        $main = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $main->tenant_id, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $this->actingAs($owner)->get('/workspace')->assertForbidden();

        $second = Branch::forceCreate(['tenant_id' => $main->tenant_id, 'name' => 'Second branch', 'code' => 'B02']);
        $unpaired = Branch::forceCreate(['tenant_id' => $main->tenant_id, 'name' => 'Unpaired branch', 'code' => 'B03']);
        $other = Tenant::create(['code' => 'outside', 'name' => 'Outside tenant']);
        $foreign = Branch::forceCreate(['tenant_id' => $other->id, 'name' => 'Foreign branch', 'code' => 'F01']);
        $device = app(DeviceEnrollment::class)->register($owner, 'Test browser', [$main->id, $second->id])['device'];
        $this->withSession(['device_id' => $device->id, 'tenant_id' => $main->tenant_id]);
        $page = $this->get('/workspace')->assertOk()->assertSee('Branch at a glance')->assertDontSee($unpaired->name);
        $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');
        foreach ([$foreign->id, $unpaired->id] as $invalid) {
            $response = $this->updateWorkspace($snapshot, ['branchSelection' => (string) $invalid], 'selectBranch')->assertOk();
            $snapshot = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($main->id, $snapshot['data']['branchId']);
            $this->assertStringContainsString('Choose a branch available on this device.', $response->json('components.0.effects.html'));
        }
        $response = $this->updateWorkspace($snapshot, ['branchSelection' => (string) $second->id], 'selectBranch')->assertOk();
        $snapshot = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($second->id, $snapshot['data']['branchId']);
        $this->get('/workspace/stock')->assertOk()->assertSee($second->name);

        config(['app.debug' => false]);
        $this->updateWorkspace($snapshot, ['branchId' => $unpaired->id])->assertStatus(419);
    }

    public function test_workspace_rechecks_device_and_stock_permission_on_component_requests(): void
    {
        $this->withoutVite();
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $cashier = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        $cashier->assignRole('Cashier');
        $device = app(DeviceEnrollment::class)->register($owner, 'Cashier browser', [$branch->id])['device'];
        $this->actingAs($cashier)->withSession(['device_id' => $device->id, 'tenant_id' => $branch->tenant_id]);

        $page = $this->get('/workspace/stock')->assertOk()->assertDontSee('>Value<', false);
        $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');
        $cashier->removeRole('Cashier');
        $this->updateWorkspace($snapshot, [])->assertForbidden();

        $cashier->assignRole('Cashier');
        $device->forceFill(['status' => 'revoked'])->save();
        $this->updateWorkspace($snapshot, [])->assertForbidden();
    }

    private function updateWorkspace(array $snapshot, array $updates, string $method = '$refresh'): TestResponse
    {
        return $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => (object) $updates,
            'calls' => [['method' => $method, 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }
}
