<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_opening_payment_evidence_advance_and_reversals(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Supplier accounts', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Books distributor'], $headers)->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/suppliers', ['name' => 'Study supplies'], $headers)->assertCreated()->json('data.id');
        $url = '/api/v1/suppliers/'.$supplier;
        $this->getJson($url.'/ledger', $headers)->assertOk()->assertJsonPath('meta.opening_status', 'not_entered');
        $this->postJson('/api/v1/suppliers/'.$other.'/opening-status/confirm-zero', [
            'reason' => 'No opening dues', 'effective_at' => '2020-01-01',
        ], $headers + ['Idempotency-Key' => 'zero'])->assertCreated();
        $this->getJson('/api/v1/suppliers/'.$other.'/ledger', $headers)->assertOk()->assertJsonPath('meta.opening_status', 'confirmed_zero');

        $due = $this->postJson($url.'/opening-entries', [
            'entry_type' => 'due', 'amount' => '1000.00', 'effective_at' => '2020-02-01', 'reason' => 'Legacy statement',
        ], $headers + ['Idempotency-Key' => 'due'])->assertCreated()->json('data.id');
        $historical = ['historical_entry' => true, 'mode' => 'bank', 'reason' => 'Bank statement'];
        $this->postJson($url.'/payments/preview', $historical + [
            'amount' => '100.00', 'paid_at' => '2020-01-02', 'included_in_opening_entry_id' => $due,
        ], $headers)->assertOk()->assertJsonPath('data.balance_delta', '0.00');
        $this->assertDatabaseCount('payments', 0);
        $advance = $this->postJson($url.'/payments', $historical + ['amount' => '200.00', 'paid_at' => '2020-01-01'],
            $headers + ['Idempotency-Key' => 'advance'])->assertCreated()->json('data.id');
        $evidence = $this->postJson($url.'/payments', $historical + [
            'amount' => '100.00', 'paid_at' => '2020-01-02', 'included_in_opening_entry_id' => $due,
        ], $headers + ['Idempotency-Key' => 'evidence'])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('payment_allocations', 0);
        $payment = $this->postJson($url.'/payments', $historical + ['amount' => '300.00', 'paid_at' => '2020-03-01'],
            $headers + ['Idempotency-Key' => 'payment'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment, 'party_opening_entry_id' => $due, 'amount' => '300.00']);
        $this->getJson($url.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '500.00');
        $this->getJson($url.'/ledger?as_of=2020-02-15', $headers)->assertOk()->assertJsonPath('meta.balance', '800.00');
        $this->getJson($url.'/ledger?recorded_as_of=2021-01-01', $headers)->assertOk()->assertJsonPath('meta.balance', '0.00');

        $application = ['opening_entry_id' => $due, 'amount' => '100.00', 'effective_at' => '2020-02-15'];
        $allocation = $this->postJson($url.'/payments/'.$advance.'/apply', $application, $headers + ['Idempotency-Key' => 'apply'])
            ->assertCreated()->json('data.allocation_id');
        $this->assertDatabaseHas('payment_allocations', ['id' => $allocation, 'effective_at' => '2020-02-14 18:30:00']);
        $this->postJson('/api/v1/suppliers/'.$other.'/payments/'.$advance.'/apply', $application,
            $headers + ['Idempotency-Key' => 'wrong-party'])->assertNotFound();
        $reverse = ['reason' => 'Duplicate bank entry'];
        $this->postJson($url.'/payments/'.$advance.'/reverse', $reverse, $headers + ['Idempotency-Key' => 'reverse-advance'])->assertConflict();
        $this->postJson($url.'/payments/'.$advance.'/allocations/'.$allocation.'/reverse', $reverse,
            $headers + ['Idempotency-Key' => 'unapply'])->assertCreated();
        $this->postJson($url.'/payments/'.$advance.'/apply', array_replace($application, ['amount' => '150.00']),
            $headers + ['Idempotency-Key' => 'historical-overapplication'])->assertUnprocessable();
        $this->postJson($url.'/payments/'.$advance.'/reverse', $reverse, $headers + ['Idempotency-Key' => 'reverse-advance'])->assertCreated();
        $this->postJson($url.'/payments/'.$evidence.'/reverse', $reverse, $headers + ['Idempotency-Key' => 'reverse-evidence'])->assertCreated();
        $this->getJson($url.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '700.00');
        $this->postJson($url.'/payments/'.$payment.'/reverse', $reverse, $headers + ['Idempotency-Key' => 'reverse-payment'])->assertCreated();
        $this->getJson($url.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '1000.00');
        $this->assertDatabaseHas('payment_allocations', ['party_opening_entry_id' => $due, 'amount' => '-300.00']);
        $this->postJson($url.'/opening-entries/'.$due.'/reverse', [
            'reason' => 'Correct legacy statement', 'effective_at' => now()->toIso8601String(),
        ], $headers + ['Idempotency-Key' => 'reverse-opening'])->assertCreated();
        $this->getJson($url.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '0.00');
        $this->assertDatabaseCount('stock_ledger', 0);
    }
}
