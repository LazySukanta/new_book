<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerOpeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_openings_and_receipts_preserve_history_and_scope(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $other = Branch::forceCreate(['tenant_id' => $branch->tenant_id, 'code' => 'B02', 'name' => 'Second branch']);
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Customer test', [$branch->id, $other->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $otherHeaders = array_replace($headers, ['X-Branch-Id' => $other->id]);
        $base = '/api/v1/customers';
        $walkIn = DB::table('customers')->where('is_system', true)->value('id');
        $this->patchJson("$base/$walkIn", ['credit_limit' => '500.00'], $headers)->assertUnprocessable();
        $this->postJson("$base/$walkIn/opening-entries", [], $headers)->assertUnprocessable();
        $id = $this->postJson($base, ['name' => 'Test customer', 'phone' => '98765-43210', 'credit_limit' => '500.00'], $headers)
            ->assertCreated()->assertJsonPath('data.credit_limit', '500.00')->json('data.id');
        $this->postJson($base, ['name' => 'Duplicate', 'phone' => '9876543210'], $headers)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $url = "$base/$id";
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.opening_status', 'not_entered')
            ->assertJsonPath('meta.balance_incomplete', true);
        $due = ['entry_type' => 'due', 'amount' => '300.00', 'effective_at' => '2020-02-01', 'reason' => 'Legacy statement'];
        $entry = $this->postJson("$url/opening-entries", $due, $headers + ['Idempotency-Key' => 'due'])->assertCreated()->json('data.id');
        $otherEntry = $this->postJson("$url/opening-entries", array_replace($due, ['amount' => '50.00']),
            $otherHeaders + ['Idempotency-Key' => 'other-due'])->assertCreated()->json('data.id');
        $evidence = ['amount' => '50.00', 'mode' => 'cash', 'historical_entry' => true, 'paid_at' => '2020-01-31',
            'reason' => 'Already counted', 'included_in_opening_entry_id' => $entry];
        $this->postJson("$url/receipts/preview", $evidence, $headers)->assertOk()->assertJsonPath('data.balance_delta', '0.00');
        $this->postJson("$url/receipts", $evidence, $otherHeaders + ['Idempotency-Key' => 'wrong-branch'])->assertNotFound();
        $this->postJson("$url/receipts", $evidence, $headers + ['Idempotency-Key' => 'evidence'])->assertCreated();
        $receipt = ['amount' => '100.00', 'mode' => 'cash', 'historical_entry' => true, 'paid_at' => '2020-03-01', 'reason' => 'Old receipt'];
        $this->postJson("$url/receipts", $receipt, $headers + ['Idempotency-Key' => 'unconfirmed'])->assertUnprocessable();
        $this->postJson("$url/receipts", $receipt + ['confirm_balance_effect' => true], $headers + ['Idempotency-Key' => 'receipt'])
            ->assertCreated()->assertJsonPath('data.direction', 'in');
        $this->assertDatabaseHas('payment_allocations', ['party_opening_entry_id' => $entry, 'amount' => '100.00']);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '200.00');
        $this->getJson("$url/ledger?as_of=2020-02-15", $headers)->assertOk()->assertJsonPath('meta.balance', '300.00');
        $this->getJson("$url/balances", $headers)->assertOk()->assertJsonPath('meta.outstanding', '250.00')
            ->assertJsonPath('meta.balance_incomplete', false);
        $reversal = ['effective_at' => '2020-04-01', 'reason' => 'Correct opening'];
        $this->postJson("$url/opening-entries/$entry/reverse", $reversal, $headers + ['Idempotency-Key' => 'blocked'])->assertConflict();
        $this->postJson("$url/opening-entries/$otherEntry/reverse", $reversal, $otherHeaders + ['Idempotency-Key' => 'reverse'])->assertCreated();
        $this->assertDatabaseHas('party_opening_entries', ['reversal_of_id' => $otherEntry, 'signed_amount' => '-50.00']);
        $this->postJson("$url/opening-status/confirm-zero", $reversal, $otherHeaders + ['Idempotency-Key' => 'zero'])->assertCreated();
        $this->getJson("$url/ledger", $otherHeaders)->assertOk()->assertJsonPath('meta.opening_status', 'confirmed_zero');
        $this->assertDatabaseCount('stock_ledger', 0);

        $owner->syncRoles(['Cashier']);
        $this->app['auth']->forgetGuards();
        $this->postJson($base, ['name' => 'Quick customer'], $headers)->assertCreated()->assertJsonPath('data.credit_limit', '0.00');
        $this->postJson($base, ['name' => 'Quick customer', 'credit_limit' => '100.00'], $headers)->assertUnprocessable();
        $this->postJson("$url/opening-entries", $due, $headers + ['Idempotency-Key' => 'staff'])->assertForbidden();
        $this->postJson("$url/receipts", $receipt + ['confirm_balance_effect' => true], $headers + ['Idempotency-Key' => 'staff-receipt'])->assertForbidden();
    }
}
