<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_and_credit_applications_reconcile_without_changing_cash_twice(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $other = Branch::forceCreate(['tenant_id' => $branch->tenant_id, 'code' => 'B02', 'name' => 'Second branch']);
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Settlement test', [$branch->id, $other->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Settlement customer'], $headers)->assertCreated()->json('data.id');
        $url = "/api/v1/customers/$customer";
        $post = fn (string $path, array $data, string $key) => $this->postJson("$url/$path", $data, $headers + ['Idempotency-Key' => $key]);
        $balance = fn (string $amount) => $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', $amount);
        $opening = ['entry_type' => 'due', 'effective_at' => '2020-02-01', 'reason' => 'Legacy balance'];
        $a = $post('opening-entries', $opening + ['amount' => '1000.00'], 'first-due')->assertCreated()->json('data.id');
        $receipt = ['mode' => 'cash', 'amount' => '400.00'];
        $normal = $post('receipts', $receipt, 'normal')->assertCreated()->assertJsonPath('data.allocations.0.party_opening_entry_id', $a)->json('data.id');
        $balance('600.00');
        $b = $post('opening-entries', array_replace($opening, ['amount' => '300.00', 'effective_at' => '2020-03-01']), 'second-due')->assertCreated()->json('data.id');

        $split = ['mode' => 'bank', 'amount' => '100.00', 'allocations' => [
            ['opening_entry_id' => $b, 'amount' => '60.00'], ['opening_entry_id' => $a, 'amount' => '20.00'],
        ]];
        $failed = $split;
        $failed['allocations'][1]['amount'] = '50.00';
        $post('receipts', $failed, 'invalid-split')->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $created = $post('receipts', $split, 'split')->assertCreated()->assertJsonCount(2, 'data.allocations');
        $post('receipts', $split, 'split')->assertCreated()->assertExactJson($created->json());
        $splitId = $created->json('data.id');
        $balance('800.00');
        $apply = ['opening_entry_id' => $a, 'amount' => '20.00'];
        $post("receipts/$splitId/apply", $apply + ['effective_at' => '2020-04-01'], 'before-receipt')->assertUnprocessable();
        $this->postJson("$url/receipts/$splitId/apply", $apply, array_replace($headers, ['X-Branch-Id' => $other->id]) + ['Idempotency-Key' => 'wrong-branch'])->assertNotFound();
        $id = $post("receipts/$splitId/apply", $apply, 'apply-remainder')->assertCreated()->json('data.allocation_id');
        $balance('800.00');
        $reverse = ['reason' => 'Correct settlement'];
        $post("receipts/$splitId/reverse", $reverse, 'blocked')->assertConflict();
        $post("receipts/$splitId/allocations/$id/reverse", $reverse, 'unapply')->assertCreated();
        $cutoff = now()->toIso8601String();
        $this->travel(2)->seconds();
        $post("receipts/$splitId/reverse", $reverse, 'reverse-split')->assertCreated()->assertJsonPath('data.direction', 'out');
        $post("receipts/$splitId/reverse", $reverse, 'double-reverse')->assertConflict();
        $balance('900.00');
        $this->getJson("$url/ledger?recorded_as_of=".urlencode($cutoff), $headers)->assertOk()->assertJsonPath('meta.balance', '800.00');

        $credit = $post('opening-entries', array_replace($opening, ['amount' => '200.00', 'entry_type' => 'credit']), 'credit')->assertCreated()->json('data.id');
        $balance('700.00');
        $post("opening-entries/$credit/apply-credit", ['opening_entry_id' => $b, 'amount' => '200.00', 'effective_at' => '2020-02-15'], 'credit-before-due')->assertUnprocessable();
        $creditAllocation = $post("opening-entries/$credit/apply-credit", ['opening_entry_id' => $b, 'amount' => '200.00'], 'credit-apply')
            ->assertCreated()->json('data.allocation_id');
        $balance('700.00');
        $post("opening-entries/$credit/reverse", $reverse + ['effective_at' => now()->toIso8601String()], 'used-credit')->assertConflict();
        $auto = $post('receipts', ['mode' => 'cash', 'amount' => '750.00'], 'fifo')->assertCreated()
            ->assertJsonPath('data.allocations.0.amount', '600.00')->assertJsonPath('data.allocations.0.party_opening_entry_id', $a)
            ->assertJsonPath('data.allocations.1.amount', '100.00')->assertJsonPath('data.allocations.1.party_opening_entry_id', $b)->json('data.id');
        $balance('-50.00');
        $post("opening-entries/$credit/credit-allocations/$creditAllocation/reverse", $reverse, 'credit-unapply')->assertCreated();
        $balance('-50.00');
        $this->assertDatabaseHas('party_credit_allocations', ['reversal_of_id' => $creditAllocation, 'amount' => '-200.00']);
        $post("receipts/$auto/apply", ['opening_entry_id' => $b, 'amount' => '50.00'], 'apply-auto-excess')->assertCreated();

        $evidence = ['mode' => 'cash', 'amount' => '50.00', 'historical_entry' => true, 'paid_at' => '2020-01-31',
            'reason' => 'Included in opening', 'included_in_opening_entry_id' => $a];
        $evidenceId = $post('receipts', $evidence, 'evidence')->assertCreated()->assertJsonCount(0, 'data.allocations')->json('data.id');
        $owner->syncRoles(['Manager']);
        $this->app['auth']->forgetGuards();
        $post("receipts/$evidenceId/reverse", $reverse, 'manager-history')->assertForbidden();
        $owner->syncRoles(['Owner']);
        $this->app['auth']->forgetGuards();
        $post("receipts/$evidenceId/reverse", $reverse, 'reverse-evidence')->assertCreated()->assertJsonPath('data.balance_effect', 'included_in_opening');
        $balance('-50.00');
        $this->assertDatabaseHas('payments', ['id' => $normal, 'status' => 'posted']);
        $this->assertDatabaseCount('stock_ledger', 0);
    }
}
