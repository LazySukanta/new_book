<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\StockPosting;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PurchaseCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private Product $product;

    private array $headers;

    private int $supplier;

    private int $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShopAccessSeeder::class);
        $this->branch = Branch::firstOrFail();
        $tenant = $this->branch->tenant_id;
        $this->owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $this->owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($this->owner, 'Correction test', [$this->branch->id]);
        $token = $this->postJson('/api/v1/auth/token', ['email' => $this->owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $this->branch->id];
        $this->supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Correction supplier'], $this->headers)->assertCreated()->json('data.id');
        $unit = DB::table('units')->insertGetId(['tenant_id' => $tenant, 'code' => 'PCS', 'name' => 'Piece']);
        $tax = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'rate_bp' => 0, 'name' => 'Zero']);
        $this->product = Product::forceCreate(['tenant_id' => $tenant, 'sku' => 'BOOK', 'name' => 'Book', 'unit_id' => $unit, 'tax_rate_id' => $tax, 'tax_inclusive' => false]);
        $this->purchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier, 'supplier_invoice_no' => 'Correction fixture', 'supplier_invoice_date' => '2020-01-01',
            'cost_includes_tax' => false, 'tax_type' => 'intra', 'input_tax_recoverable' => true,
            'items' => [['product_id' => $this->product->id, 'qty' => '10', 'unit_cost' => '100.00', 'rate_bp' => 0]],
            'payments' => [['mode' => 'bank', 'amount' => '800.00']],
        ], $this->headers + ['Idempotency-Key' => 'initial'])->assertCreated()->json('data.id');
    }

    private function change(string $qty = '10', string $cost = '100.00', ?string $remaining = null, int $revision = 1): array
    {
        $line = ['product_id' => $this->product->id, 'qty' => $qty, 'unit_cost' => $cost, 'rate_bp' => 0];
        if ($remaining !== null) {
            $line['confirmed_remaining'] = $remaining;
        }

        return ['expected_revision' => $revision, 'reason' => 'Correct supplier document', 'items' => [$line]];
    }

    private function preview(array $data): TestResponse
    {
        return $this->postJson('/api/v1/purchases/'.$this->purchase.'/revision-preview', $data, $this->headers);
    }

    private function commit(array $data, string $key = 'correction'): TestResponse
    {
        return $this->patchJson('/api/v1/purchases/'.$this->purchase, $data, $this->headers + ['Idempotency-Key' => $key]);
    }

    public function test_cost_correction_after_issue_keeps_original_cost_and_requires_current_preview(): void
    {
        $issue = app(StockPosting::class)->issueSale($this->owner, $this->branch->id, $this->product->id, 9001, '4');
        $original = (array) DB::table('stock_ledger')->where('id', $issue->id)->first();
        $data = $this->change(cost: '110.00', remaining: '6');
        $preview = $this->preview($data)->assertOk()->assertJsonPath('data.effects.0.inventory_value_change', '60.00')
            ->assertJsonPath('data.effects.0.valuation_variance', '-40.00');
        $this->assertDatabaseCount('purchase_revisions', 1);
        $data['preview_hash'] = $preview->json('data.preview_hash');
        $first = $this->commit($data)->assertOk()->assertJsonPath('data.current_revision', 2);
        $this->commit($data)->assertOk()->assertExactJson($first->json());
        $this->commit($data, 'stale')->assertConflict()->assertJsonPath('code', 'PURCHASE_REVISION_CONFLICT');
        $this->commit(array_replace($data, ['reason' => 'Changed']), 'correction')->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame($original, (array) DB::table('stock_ledger')->where('id', $issue->id)->first());
        $this->assertDatabaseHas('stock_balances', ['product_id' => $this->product->id, 'qty' => '6.000', 'inventory_value' => '660.00']);
        $this->assertDatabaseCount('purchase_revisions', 2);
        $this->getJson('/api/v1/purchases/'.$this->purchase.'/revisions', $this->headers)->assertOk()
            ->assertJsonPath('data.1.after_snapshot.items.0.unit_cost', '100.00')->assertJsonPath('data.0.before_snapshot.items.0.unit_cost', '100.00');
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_quantity_reduction_and_deletion_retain_paid_money_and_history(): void
    {
        $data = $this->change(qty: '6');
        $data['preview_hash'] = $this->preview($data)->assertOk()->assertJsonPath('data.settlement.advance_to_original_supplier', '200.00')->json('data.preview_hash');
        $this->commit($data)->assertOk()->assertJsonPath('data.total', '600.00')->assertJsonPath('data.paid_amount', '600.00');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 3);
        $this->assertDatabaseHas('stock_balances', ['qty' => '6.000', 'inventory_value' => '600.00']);
        $this->getJson('/api/v1/suppliers/'.$this->supplier.'/ledger', $this->headers)->assertOk()->assertJsonPath('meta.balance', '-200.00');
        $delete = ['expected_revision' => 2, 'reason' => 'Duplicate entry', 'action' => 'soft_delete'];
        $delete['preview_hash'] = $this->preview($delete)->assertOk()->assertJsonPath('data.effects.0.qty_change', '-6.000')->json('data.preview_hash');
        $url = '/api/v1/purchases/'.$this->purchase;
        $result = $this->deleteJson($url, $delete, $this->headers + ['Idempotency-Key' => 'delete'])->assertOk()->assertJsonPath('data.status', 'deleted');
        $this->postJson($url.'/cancel', $delete, $this->headers + ['Idempotency-Key' => 'delete'])->assertOk()->assertExactJson($result->json());
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('purchase_items', 1);
        $this->assertDatabaseCount('purchase_revisions', 3);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('stock_balances', ['qty' => '0.000', 'inventory_value' => '0.00']);
        $this->getJson('/api/v1/purchases', $this->headers)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/purchases?status=deleted', $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($url, $this->headers)->assertOk()->assertJsonPath('data.current_revision', 3);
        $this->getJson('/api/v1/suppliers/'.$this->supplier.'/ledger', $this->headers)->assertOk()->assertJsonPath('meta.balance', '-800.00');
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_stock_and_payment_changes_invalidate_preview_and_invalid_reduction_has_no_effect(): void
    {
        $data = $this->change(qty: '8');
        $data['preview_hash'] = $this->preview($data)->assertOk()->json('data.preview_hash');
        app(StockPosting::class)->issueSale($this->owner, $this->branch->id, $this->product->id, 9002, '1');
        $this->commit($data)->assertConflict()->assertJsonPath('code', 'PURCHASE_PREVIEW_CHANGED');
        $data['preview_hash'] = $this->preview($data)->assertOk()->json('data.preview_hash');
        $this->postJson('/api/v1/suppliers/'.$this->supplier.'/payments', ['amount' => '20.00', 'mode' => 'cash'], $this->headers + ['Idempotency-Key' => 'pay'])->assertCreated();
        $this->commit($data)->assertConflict()->assertJsonPath('code', 'PURCHASE_PREVIEW_CHANGED');
        app(StockPosting::class)->issueSale($this->owner, $this->branch->id, $this->product->id, 9003, '8');
        $this->preview($this->change(qty: '8'))->assertUnprocessable()->assertJsonValidationErrors('qty');
        $this->assertDatabaseCount('purchase_revisions', 1);
        $this->assertDatabaseHas('purchases', ['id' => $this->purchase, 'current_revision' => 1, 'total' => '1000.00']);
        $this->preview($this->change(cost: '110.00'))->assertUnprocessable()->assertJsonValidationErrors('confirmed_remaining');
        $this->preview($this->change(cost: '110.00', remaining: '2'))->assertUnprocessable();
    }

    public function test_supplier_change_preserves_payment_owner_and_historical_balance(): void
    {
        $cutoff = now()->toIso8601String();
        $this->travel(2)->seconds();
        $newSupplier = $this->postJson('/api/v1/suppliers', ['name' => 'Correct supplier'], $this->headers)->assertCreated()->json('data.id');
        $data = ['expected_revision' => 1, 'reason' => 'Wrong supplier', 'supplier_id' => $newSupplier];
        $data['preview_hash'] = $this->preview($data)->assertOk()->assertJsonPath('data.settlement.advance_to_original_supplier', '800.00')->json('data.preview_hash');
        $this->commit($data)->assertOk()->assertJsonPath('data.balance_amount', '1000.00');
        $this->assertDatabaseHas('payments', ['supplier_id' => $this->supplier, 'amount' => '800.00']);
        $this->assertDatabaseCount('stock_ledger', 1);
        $this->getJson('/api/v1/suppliers/'.$this->supplier.'/ledger', $this->headers)->assertOk()->assertJsonPath('meta.balance', '-800.00');
        $this->getJson('/api/v1/suppliers/'.$newSupplier.'/ledger', $this->headers)->assertOk()->assertJsonPath('meta.balance', '1000.00');
        $this->getJson('/api/v1/suppliers/'.$this->supplier.'/ledger?recorded_as_of='.urlencode($cutoff), $this->headers)->assertOk()->assertJsonPath('meta.balance', '200.00');
        $this->getJson('/api/v1/suppliers/'.$this->supplier.'/ledger?as_of='.urlencode($cutoff), $this->headers)->assertOk()->assertJsonPath('meta.balance', '200.00');
    }

    public function test_metadata_edit_has_no_stock_effect_and_staff_cannot_correct(): void
    {
        $data = ['expected_revision' => 1, 'reason' => 'Invoice typo', 'supplier_invoice_no' => 'Corrected number'];
        $data['preview_hash'] = $this->preview($data)->assertOk()->assertJsonPath('data.effects', [])->json('data.preview_hash');
        foreach (['Manager', 'Cashier'] as $role) {
            $this->owner->syncRoles([$role]);
            app('auth')->forgetGuards();
            $this->preview($data)->assertForbidden();
            $this->commit($data)->assertForbidden();
            $this->deleteJson('/api/v1/purchases/'.$this->purchase, $data, $this->headers)->assertForbidden();
            $this->getJson('/api/v1/purchases/'.$this->purchase.'/revisions', $this->headers)->assertForbidden();
        }
        $this->owner->syncRoles(['Owner']);
        app('auth')->forgetGuards();
        $this->commit($data)->assertOk();
        $this->assertDatabaseCount('stock_ledger', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('purchases', ['supplier_invoice_no' => 'Corrected number', 'supplier_invoice_date' => '2020-01-01']);
    }

    public function test_failure_after_stock_and_allocations_rolls_back_every_effect(): void
    {
        $data = $this->change(qty: '6');
        $data['preview_hash'] = $this->preview($data)->assertOk()->json('data.preview_hash');
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new \RuntimeException('Simulated failure after audit insert');
            }
        });
        $this->commit($data)->assertStatus(500);
        $this->assertDatabaseCount('stock_ledger', 1);
        $this->assertDatabaseCount('purchase_revisions', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('stock_balances', ['qty' => '10.000', 'inventory_value' => '1000.00']);
        $this->assertDatabaseHas('purchases', ['id' => $this->purchase, 'total' => '1000.00', 'current_revision' => 1]);
        $this->assertDatabaseMissing('idempotency_requests', ['key' => 'correction']);
    }

    public function test_product_replacement_retains_original_line_identity_and_ledger(): void
    {
        $original = DB::table('purchase_items')->where('purchase_id', $this->purchase)->first();
        $replacement = $this->product->replicate()->forceFill(['sku' => 'REPLACEMENT', 'name' => 'Correct book']);
        $replacement->save();
        $data = ['expected_revision' => 1, 'reason' => 'Wrong book', 'items' => [
            ['product_id' => $replacement->id, 'qty' => '2', 'unit_cost' => '50.00', 'rate_bp' => 0],
        ]];
        $data['preview_hash'] = $this->preview($data)->assertOk()->json('data.preview_hash');
        $this->commit($data)->assertOk();
        $this->assertDatabaseHas('purchase_items', ['id' => $original->id, 'product_id' => $this->product->id, 'is_removed' => true]);
        $this->assertDatabaseHas('stock_ledger', ['purchase_item_id' => $original->id, 'qty_change' => '10.000', 'value_change' => '1000.00']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $this->product->id, 'qty' => '0.000', 'inventory_value' => '0.00']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $replacement->id, 'qty' => '2.000', 'inventory_value' => '100.00']);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_backdated_advance_application_respects_purchase_capacity_before_a_revision(): void
    {
        $url = '/api/v1/suppliers/'.$this->supplier.'/payments';
        $payment = $this->postJson($url, ['mode' => 'bank', 'amount' => '500.00', 'auto_allocate' => false],
            $this->headers + ['Idempotency-Key' => 'advance'])->assertCreated()->json('data.id');
        $earlier = now()->toIso8601String();
        $this->travel(2)->seconds();
        $data = $this->change(cost: '200.00', remaining: '10');
        $data['preview_hash'] = $this->preview($data)->assertOk()->json('data.preview_hash');
        $this->commit($data)->assertOk();
        $application = ['purchase_id' => $this->purchase, 'amount' => '500.00', 'effective_at' => $earlier];
        $this->postJson($url.'/'.$payment.'/apply', $application, $this->headers + ['Idempotency-Key' => 'past-apply'])->assertUnprocessable();
        $application['effective_at'] = now()->toIso8601String();
        $this->postJson($url.'/'.$payment.'/apply', $application, $this->headers + ['Idempotency-Key' => 'current-apply'])->assertCreated();
        $this->assertDatabaseHas('purchases', ['id' => $this->purchase, 'paid_amount' => '1300.00', 'balance_amount' => '700.00']);
    }

    public function test_incompatible_consumed_allocations_block_without_mutation(): void
    {
        $allocation = (array) DB::table('payment_allocations')->where('purchase_id', $this->purchase)->first();
        unset($allocation['id']);
        $allocation['amount'] = '1.00';
        DB::table('payment_allocations')->insert($allocation);
        $this->preview($this->change(qty: '6'))->assertConflict()->assertJsonPath('code', 'PURCHASE_ALLOCATION_CONFLICT');
        $this->assertDatabaseCount('purchase_revisions', 1);
        $this->assertDatabaseCount('stock_ledger', 1);
    }
}
