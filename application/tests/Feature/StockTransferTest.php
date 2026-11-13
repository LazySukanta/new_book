<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use Illuminate\Support\Facades\DB;

class StockTransferTest extends PurchasePostingTest
{
    public function test_transfer_drafts_dispatch_stock_once_and_preserve_in_transit_value(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $owner = User::role('Owner')->firstOrFail();
        $source = Branch::firstOrFail();
        $source->forceFill(['gstin' => '36ABCDE1234F1Z5'])->save();
        $destination = Branch::forceCreate(['tenant_id' => $source->tenant_id, 'code' => 'DST', 'name' => 'Destination', 'gstin' => $source->gstin]);
        $pair = app(DeviceEnrollment::class)->register($owner, 'Transfer counter', [$source->id, $destination->id]);
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $source->id];
        $product = Product::firstOrFail();
        $payload = ['destination_branch_id' => $destination->id, 'note' => 'Restock destination', 'items' => [['product_id' => $product->id, 'qty' => '10']]];
        $url = '/api/v1/stock-transfers';
        $first = $this->postJson($url, $payload, $headers + ['Idempotency-Key' => 'draft'])->assertCreated();
        $id = $first->json('data.id');
        $this->postJson($url, $payload, $headers + ['Idempotency-Key' => 'draft'])->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $source->id, 'product_id' => $product->id, 'qty' => '20.000', 'inventory_value' => '218.00']);
        $this->assertDatabaseCount('issued_documents', 0);
        $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 1], $headers + ['Idempotency-Key' => 'missing-series'])
            ->assertConflict()->assertJsonPath('code', 'DOCUMENT_SERIES_UNAVAILABLE');
        $this->assertDatabaseHas('stock_transfers', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('stock_ledger', 2);
        $this->postJson('/api/v1/document-series', ['financial_year' => ShopCalendar::financialYear(now()), 'document_type' => 'stock_transfer',
            'owning_channel' => 'web', 'prefixes' => ['T']], $headers)->assertCreated();
        $this->patchJson($url.'/'.$id, $payload + ['expected_version' => 1], $headers + ['Idempotency-Key' => 'edit'])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 1], $headers + ['Idempotency-Key' => 'stale'])->assertConflict();
        $destination->forceFill(['gstin' => '27ABCDE1234F1Z5'])->save();
        $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 2], $headers + ['Idempotency-Key' => 'gst-mismatch'])->assertUnprocessable();
        $destination->forceFill(['gstin' => $source->gstin])->save();
        $dispatch = $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 2], $headers + ['Idempotency-Key' => 'dispatch'])->assertOk();
        $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 2], $headers + ['Idempotency-Key' => 'dispatch'])->assertOk()->assertExactJson($dispatch->json());
        $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 2], $headers + ['Idempotency-Key' => 'dispatch-again'])->assertConflict();
        $this->deleteJson($url.'/'.$id, ['expected_version' => 3], $headers + ['Idempotency-Key' => 'delete-dispatched'])->assertConflict();
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $source->id, 'product_id' => $product->id, 'qty' => '10.000', 'inventory_value' => '109.00']);
        $this->assertDatabaseHas('stock_transfer_items', ['transfer_id' => $id, 'qty_dispatched' => '10.000', 'dispatched_value' => '109.00']);
        $this->assertDatabaseCount('issued_documents', 1);
        $this->getJson($url.'/'.$id, array_replace($headers, ['X-Branch-Id' => $destination->id]))->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->postJson($url.'/'.$id.'/dispatch', ['expected_version' => 3], array_replace($headers, ['X-Branch-Id' => $destination->id, 'Idempotency-Key' => 'wrong-endpoint']))->assertNotFound();
        $tooMany = array_replace($payload, ['items' => [['product_id' => $product->id, 'qty' => '11']]]);
        $short = $this->postJson($url, $tooMany, $headers + ['Idempotency-Key' => 'short-draft'])->assertCreated()->json('data.id');
        $this->postJson($url.'/'.$short.'/dispatch', ['expected_version' => 1], $headers + ['Idempotency-Key' => 'short-dispatch'])->assertUnprocessable();
        $this->deleteJson($url.'/'.$short, ['expected_version' => 1], $headers + ['Idempotency-Key' => 'delete-draft'])->assertOk();
        $this->postJson($url, array_replace($payload, ['destination_branch_id' => $source->id]), $headers + ['Idempotency-Key' => 'same-branch'])->assertUnprocessable();
        $this->artisan('stock:reconcile')->assertSuccessful();
    }
}
