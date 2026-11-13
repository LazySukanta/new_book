<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferReceiptTest extends StockTransferTest
{
    public function test_receipt_recovery_and_loss_conserve_dispatched_quantities_and_value(): void
    {
        $this->test_transfer_drafts_dispatch_stock_once_and_preserve_in_transit_value();
        $owner = User::role('Owner')->firstOrFail();
        $record = DB::table('stock_transfers')->where('status', 'dispatched')->first();
        $item = DB::table('stock_transfer_items')->where('transfer_id', $record->id)->first();
        $pair = app(DeviceEnrollment::class)->register($owner, 'Receiving terminal', [$record->source_branch_id, $record->destination_branch_id]);
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $record->destination_branch_id];
        $url = '/api/v1/stock-transfers/'.$record->id;
        $data = ['expected_version' => 3, 'items' => [['transfer_item_id' => $item->id, 'qty' => '8']]];
        $this->postJson($url.'/receive', $data, array_replace($headers, ['X-Branch-Id' => $record->source_branch_id, 'Idempotency-Key' => 'source-denied']))->assertNotFound();
        $this->postJson($url.'/receive', array_replace($data, ['items' => [['transfer_item_id' => $item->id, 'qty' => '11']]]), $headers + ['Idempotency-Key' => 'over-receive'])->assertUnprocessable();
        $failure = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failure): void {
            if ($failure && str_starts_with($query, 'insert into `audit_logs`')) {
                throw new RuntimeException('Simulated receipt audit failure');
            }
        });
        $this->postJson($url.'/receive', $data, $headers + ['Idempotency-Key' => 'receipt'])->assertStatus(500);
        $failure = false;
        $this->assertDatabaseHas('stock_transfers', ['id' => $record->id, 'status' => 'dispatched', 'received_at' => null]);
        $this->assertDatabaseMissing('stock_balances', ['branch_id' => $record->destination_branch_id, 'product_id' => $item->product_id]);
        $received = $this->postJson($url.'/receive', $data, $headers + ['Idempotency-Key' => 'receipt'])->assertOk()->assertJsonPath('data.status', 'discrepancy');
        $this->postJson($url.'/receive', $data, $headers + ['Idempotency-Key' => 'receipt'])->assertOk()->assertExactJson($received->json());
        $this->postJson($url.'/receive', array_replace($data, ['expected_version' => 4]), $headers + ['Idempotency-Key' => 'second-receipt'])->assertConflict();
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $record->destination_branch_id, 'qty' => '8.000', 'inventory_value' => '87.20']);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.items.0.unresolved_qty', '2.000')->assertJsonPath('data.items.0.in_transit_value', '21.80');
        $sourceReports = array_replace($headers, ['X-Branch-Id' => $record->source_branch_id]);
        $this->getJson('/api/v1/reports/in-transit', $sourceReports)->assertOk()->assertJsonPath('data.0.in_transit_value', '21.80')->assertJsonPath('meta.attribution', 'source_branch');
        $this->getJson('/api/v1/reports/transfer-discrepancies', $sourceReports)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reports/in-transit', $headers)->assertOk()->assertJsonCount(0, 'data');
        $resolution = ['expected_version' => 4, 'transfer_item_id' => $item->id, 'resolution' => 'recovered', 'qty' => '1', 'reason' => 'Found in delivery vehicle'];
        $recovery = $this->postJson($url.'/resolutions', $resolution, $headers + ['Idempotency-Key' => 'recover'])->assertCreated()->assertJsonPath('data.value', '10.90');
        $this->postJson($url.'/resolutions', $resolution, $headers + ['Idempotency-Key' => 'recover'])->assertCreated()->assertExactJson($recovery->json());
        $resolution = array_replace($resolution, ['expected_version' => 5, 'resolution' => 'written_off', 'reason' => 'Confirmed missing']);
        $this->postJson($url.'/resolutions', array_replace($resolution, ['qty' => '2']), $headers + ['Idempotency-Key' => 'over-resolution'])->assertUnprocessable();
        $this->postJson($url.'/resolutions', $resolution, $headers + ['Idempotency-Key' => 'write-off'])->assertCreated()->assertJsonPath('data.status', 'resolved');
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.items.0.unresolved_qty', '0.000')->assertJsonPath('data.items.0.in_transit_value', '0.00')->assertJsonCount(2, 'data.resolutions');
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $record->source_branch_id, 'qty' => '10.000', 'inventory_value' => '109.00']);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $record->destination_branch_id, 'qty' => '9.000', 'inventory_value' => '98.10']);
        $this->assertDatabaseHas('stock_transfer_items', ['id' => $item->id, 'qty_received' => '8.000', 'qty_recovered' => '1.000', 'qty_written_off' => '1.000', 'written_off_value' => '10.90']);
        $this->assertDatabaseCount('issued_documents', 1);
        $this->assertDatabaseCount('stock_ledger', 5);
        $this->getJson('/api/v1/reports/in-transit', $sourceReports)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/transfer-resolutions', $sourceReports)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reports/transfers', $sourceReports)->assertOk()->assertJsonPath('data.0.written_off_value', '10.90');
        $this->artisan('stock:reconcile')->assertSuccessful();
        $product = \App\Models\Product::firstOrFail();
        $fractional = \App\Models\Product::forceCreate(['tenant_id' => $owner->tenant_id, 'sku' => 'PENNY', 'name' => 'Penny allocation',
            'unit_id' => $product->unit_id, 'tax_rate_id' => $product->tax_rate_id, 'tax_inclusive' => false]);
        $purchaseItem = \Tests\Support\PurchaseFixture::create($owner, $record->source_branch_id, $fractional->id);
        app(\App\Services\StockPosting::class)->receive($owner, $record->source_branch_id, $fractional->id, $purchaseItem, '3', '0.003333333');
        $sourceHeaders = array_replace($headers, ['X-Branch-Id' => $record->source_branch_id]);
        $small = $this->postJson('/api/v1/stock-transfers', ['destination_branch_id' => $record->destination_branch_id,
            'items' => [['product_id' => $fractional->id, 'qty' => '3']]], $sourceHeaders + ['Idempotency-Key' => 'penny-draft'])->assertCreated()->json('data.id');
        $smallUrl = '/api/v1/stock-transfers/'.$small;
        $this->postJson($smallUrl.'/dispatch', ['expected_version' => 1], $sourceHeaders + ['Idempotency-Key' => 'penny-dispatch'])->assertOk();
        $smallItem = DB::table('stock_transfer_items')->where('transfer_id', $small)->value('id');
        $this->postJson($smallUrl.'/receive', ['expected_version' => 2, 'items' => [['transfer_item_id' => $smallItem, 'qty' => '0']]],
            $headers + ['Idempotency-Key' => 'penny-receive'])->assertOk();
        foreach ([3, 4, 5] as $version) {
            $this->postJson($smallUrl.'/resolutions', ['expected_version' => $version, 'transfer_item_id' => $smallItem,
                'resolution' => 'recovered', 'qty' => '1', 'reason' => 'Recovered package'], $headers + ['Idempotency-Key' => 'penny-recovery-'.$version])->assertCreated();
        }
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $record->destination_branch_id, 'product_id' => $fractional->id, 'qty' => '3.000', 'inventory_value' => '0.01']);
        $this->getJson($smallUrl, $headers)->assertOk()->assertJsonPath('data.items.0.in_transit_value', '0.00');
        $this->getJson('/api/v1/reports/gst-sales', $sourceHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/documents-issued?document_type=stock_transfer', $sourceHeaders)->assertOk()->assertJsonPath('data.0.total', 2);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }
}
