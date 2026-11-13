<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Illuminate\Support\Facades\DB;

class HistoricalReportTest extends PurchasePostingTest
{
    public function test_gst_snapshots_credit_notes_series_rollover_and_cancellation_cutoffs(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $headers = $this->headers();
        $branch = Branch::firstOrFail();
        $branch->forceFill(['gstin' => '36ABCDE1234F1Z5', 'state_code' => '36'])->save();
        DB::table('shop_settings')->updateOrInsert(['tenant_id' => $branch->tenant_id], ['name' => 'GST reports shop', 'gst_registration_type' => 'regular']);
        $product = \App\Models\Product::firstOrFail();
        $product->forceFill(['retail_price' => '118.00'])->save();
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Registered customer', 'gstin' => '36ABCDE1234F1Z5', 'state_code' => '36'], $headers)->assertCreated()->json('data.id');
        $year = \App\Services\ShopCalendar::financialYear(now());
        $series = ['financial_year' => $year, 'owning_channel' => 'web'];
        $this->postJson('/api/v1/document-series', $series + ['document_type' => 'tax_invoice', 'prefixes' => ['A', 'B']], $headers)->assertCreated();
        $this->postJson('/api/v1/document-series', $series + ['document_type' => 'credit_note', 'prefixes' => ['X']], $headers)->assertCreated();
        $this->postJson('/api/v1/document-series', array_replace($series, ['owning_channel' => 'mobile']) + ['document_type' => 'tax_invoice', 'prefixes' => ['C']], $headers)->assertCreated();
        DB::table('document_series')->where(['branch_id' => $branch->id, 'prefix' => 'A'])->update(['next_number' => 99999]);
        $sell = function (string $qty, string $amount, string $key) use ($headers, $product, $customer) {
            $cart = ['customer_id' => $customer, 'items' => [['product_id' => $product->id, 'qty' => $qty]]];
            $fingerprint = $this->postJson('/api/v1/sales/calculate', $cart, $headers)->assertOk()->json('data.pricing_fingerprint');
            return $this->postJson('/api/v1/sales', $cart + ['acknowledged_pricing_fingerprint' => $fingerprint, 'payments' => [['mode' => 'cash', 'amount' => $amount]]], $headers + ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
        };
        $first = $sell('2', '236.00', 'gst-first');
        $second = $sell('1', '118.00', 'gst-second');
        $saleItem = DB::table('sale_items')->where('sale_id', $first)->value('id');
        $this->postJson('/api/v1/sales/'.$first.'/returns', ['reason' => 'One item returned', 'refund_mode' => 'cash', 'items' => [['sale_item_id' => $saleItem, 'qty' => '1']]], $headers + ['Idempotency-Key' => 'gst-note'])->assertCreated();
        $cutoff = now()->toIso8601String();
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/sales/'.$second.'/cancel', ['reason' => 'Cancelled bill', 'refund_mode' => 'cash'], $headers + ['Idempotency-Key' => 'gst-cancel'])->assertOk();
        DB::table('customers')->where('id', $customer)->update(['name' => 'Changed master', 'gstin' => null]);
        $third = $sell('1', '118.00', 'gst-third');
        $rows = $this->getJson('/api/v1/reports/gst-sales', $headers)->assertOk()->assertJsonCount(4, 'data')->json('data');
        $this->assertSame('Registered customer', $rows[0]['party_name']);
        $this->assertSame('B2B', $rows[0]['section']);
        $this->assertSame(1, collect($rows)->where('section', 'B2C')->count());
        $this->assertSame('200.00', $rows[0]['taxable']);
        $note = collect($rows)->firstWhere('document_type', 'credit_note');
        $this->assertSame('-118.00', $note['total']);
        $this->assertSame('-100.00', $note['taxable']);
        $cancelled = collect($rows)->firstWhere('status_at_cutoff', 'cancelled');
        $this->assertSame('0.00', $cancelled['net_total']);
        $past = $this->getJson('/api/v1/reports/gst-sales?recorded_as_of='.urlencode($cutoff), $headers)->assertOk()->json('data');
        $this->assertSame(0, collect($past)->where('status_at_cutoff', 'cancelled')->count());
        $seriesRows = $this->getJson('/api/v1/reports/documents-issued', $headers)->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame(4, collect($seriesRows)->sum('total'));
        $this->assertSame(1, collect($seriesRows)->sum('cancelled'));
        $this->assertSame(3, collect($seriesRows)->sum('net'));
        $this->assertSame(0, collect($seriesRows)->sum('gaps'));
        $this->getJson('/api/v1/reports/documents-issued?document_type=credit_note', $headers)->assertOk()->assertJsonCount(1, 'data');
        // Simulate a corrupted registry serial to verify gap detection, then restore the fixture.
        $registryId = DB::table('sales')->where('id', $third)->value('issued_document_id');
        DB::table('issued_documents')->where('id', $registryId)->update(['serial' => 3]);
        $gapped = $this->getJson('/api/v1/reports/documents-issued', $headers)->assertOk()->json('data');
        $this->assertSame(1, collect($gapped)->sum('gaps'));
        $this->assertSame(2, collect($gapped)->firstWhere('prefix', 'B')['gap_exceptions'][0]['first_missing_serial']);
        DB::table('issued_documents')->where('id', $registryId)->update(['serial' => 2]);
    }

    private function headers(): array
    {
        $owner = User::role('Owner')->firstOrFail();
        $branch = Branch::firstOrFail();
        $pair = app(DeviceEnrollment::class)->register($owner, 'Historical reports', [$branch->id]);
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        return ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
    }

    public function test_purchase_reports_reconstruct_versions_and_soft_deletion_at_cutoffs(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $headers = $this->headers();
        $purchase = DB::table('purchases')->orderBy('id')->first();
        $item = DB::table('purchase_items')->where('purchase_id', $purchase->id)->first();
        $cutoff = now()->toIso8601String();
        $this->travel(2)->seconds();
        $data = ['expected_revision' => 1, 'reason' => 'Correct received quantity', 'items' => [['product_id' => $item->product_id, 'qty' => '12', 'unit_cost' => '10.00', 'rate_bp' => 1800]]];
        $data['preview_hash'] = $this->postJson('/api/v1/purchases/'.$purchase->id.'/revision-preview', $data, $headers)->assertOk()->json('data.preview_hash');
        $this->patchJson('/api/v1/purchases/'.$purchase->id, $data, $headers + ['Idempotency-Key' => 'historical-revision'])->assertOk();
        $period = 'from=2020-01-01&to=2020-01-01';
        $this->getJson('/api/v1/reports/gst-purchases?'.$period, $headers)->assertOk()->assertJsonPath('data.0.total', '141.60')->assertJsonPath('data.0.revision_no', 2);
        $this->getJson('/api/v1/reports/gst-purchases?'.$period.'&recorded_as_of='.urlencode($cutoff), $headers)->assertOk()->assertJsonPath('data.0.total', '118.00')->assertJsonPath('data.0.revision_no', 1);
        $this->getJson('/api/v1/reports/stock-as-of?recorded_as_of='.urlencode($cutoff), $headers)->assertOk()->assertJsonPath('data.0.qty', '20.000')->assertJsonPath('data.0.value', '218.00');
        $this->getJson('/api/v1/reports/stock-as-of', $headers)->assertOk()->assertJsonPath('data.0.qty', '22.000')->assertJsonPath('data.0.value', '238.00');
        $delete = ['expected_revision' => 2, 'reason' => 'Remove erroneous purchase', 'action' => 'soft_delete'];
        $delete['preview_hash'] = $this->postJson('/api/v1/purchases/'.$purchase->id.'/revision-preview', $delete, $headers)->assertOk()->json('data.preview_hash');
        $this->deleteJson('/api/v1/purchases/'.$purchase->id, $delete, $headers + ['Idempotency-Key' => 'historical-delete'])->assertOk();
        $this->getJson('/api/v1/reports/gst-purchases?'.$period, $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reports/gst-purchases?'.$period.'&recorded_as_of='.urlencode($cutoff), $headers)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_late_financial_entries_and_prior_zero_status_are_reproducible(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $headers = $this->headers();
        $due = $this->postJson('/api/v1/customers', ['name' => 'Historical due'], $headers)->assertCreated()->json('data.id');
        $zero = $this->postJson('/api/v1/customers', ['name' => 'Historical zero'], $headers)->assertCreated()->json('data.id');
        $unknown = $this->postJson('/api/v1/customers', ['name' => 'Historical unknown'], $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers/'.$due.'/opening-entries', ['entry_type' => 'due', 'amount' => '100.00', 'effective_at' => '2020-01-01', 'reason' => 'Opening'], $headers + ['Idempotency-Key' => 'due-opening'])->assertCreated();
        $this->postJson('/api/v1/customers/'.$zero.'/opening-status/confirm-zero', ['effective_at' => '2020-01-01', 'reason' => 'Confirmed no dues'], $headers + ['Idempotency-Key' => 'zero-opening'])->assertCreated();
        $cutoff = now()->toIso8601String();
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/customers/'.$zero.'/opening-entries', ['entry_type' => 'due', 'amount' => '50.00', 'effective_at' => '2020-01-01', 'reason' => 'Late correction'], $headers + ['Idempotency-Key' => 'late-zero-due'])->assertCreated();
        $this->postJson('/api/v1/customers/'.$due.'/receipts', ['mode' => 'cash', 'amount' => '40.00', 'historical_entry' => true, 'paid_at' => '2020-02-01', 'confirm_balance_effect' => true, 'reason' => 'Late receipt', 'auto_allocate' => false], $headers + ['Idempotency-Key' => 'late-receipt'])->assertCreated();
        $report = '/api/v1/reports/customer-as-of?as_of=2020-02-02';
        $current = $this->getJson($report, $headers)->assertOk()->json('data');
        $past = $this->getJson($report.'&recorded_as_of='.urlencode($cutoff), $headers)->assertOk()->json('data');
        $this->assertSame('60.00', collect($current)->firstWhere('party_id', $due)['balance']);
        $this->assertSame('100.00', collect($past)->firstWhere('party_id', $due)['balance']);
        $this->assertSame('confirmed_zero', collect($past)->firstWhere('party_id', $zero)['opening_status']);
        $this->assertSame('entered', collect($current)->firstWhere('party_id', $zero)['opening_status']);
        $this->assertSame('not_entered', collect($past)->firstWhere('party_id', $unknown)['opening_status']);
        $this->getJson('/api/v1/reports/supplier-as-of', $headers)->assertOk()->assertJsonPath('data.0.balance', '216.00');
        User::role('Owner')->firstOrFail()->syncRoles(['Cashier']);
        $this->app['auth']->forgetGuards();
        $this->getJson($report, $headers)->assertForbidden();
        $manager = User::role('Cashier')->firstOrFail();
        $manager->syncRoles(['Manager']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports/gst-purchases?recorded_as_of='.urlencode($cutoff), $headers)->assertForbidden();
        $this->getJson('/api/v1/reports/gst-purchases', $headers)->assertOk();
    }
}
