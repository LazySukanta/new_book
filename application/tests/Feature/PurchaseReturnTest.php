<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use Illuminate\Support\Facades\DB;

class PurchaseReturnTest extends PurchasePostingTest
{
    public function test_supplier_returns_conserve_stock_dues_and_refunds_and_retry_once(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $owner = User::role('Owner')->firstOrFail();
        $branch = Branch::firstOrFail();
        $pair = app(DeviceEnrollment::class)->register($owner, 'Returns', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $purchase = DB::table('purchases')->where('status', 'posted')->first();
        $line = DB::table('purchase_items')->where('purchase_id', $purchase->id)->where('is_removed', false)->first();
        $this->postJson('/api/v1/document-series', ['branch_id' => $branch->id, 'financial_year' => ShopCalendar::financialYear(now()),
            'document_type' => 'purchase_return', 'owning_channel' => 'web', 'prefixes' => ['R']], $headers)->assertCreated();
        $payload = ['expected_revision' => $purchase->current_revision, 'reason' => 'Damaged goods', 'refund_amount' => '0.00', 'refund_mode' => 'cash',
            'items' => [['purchase_item_id' => $line->id, 'qty' => '1']]];
        $url = '/api/v1/purchases/'.$purchase->id.'/returns';
        $before = DB::table('stock_balances')->where('product_id', $line->product_id)->value('qty');
        $response = $this->postJson($url, $payload, $headers + ['Idempotency-Key' => 'supplier-return-1'])->assertCreated();
        $this->postJson($url, $payload, $headers + ['Idempotency-Key' => 'supplier-return-1'])->assertCreated()->assertExactJson($response->json());
        $this->assertDatabaseCount('purchase_returns', 1);
        $returnTotal = $response->json('data.total');
        $this->assertSame('11.80', $returnTotal);
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'balance_amount' => '86.20']);
        $this->getJson('/api/v1/suppliers/'.$purchase->supplier_id.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '204.20');
        $this->assertSame((string) \Brick\Math\BigDecimal::of($before)->minus('1')->toScale(3), DB::table('stock_balances')->where('product_id', $line->product_id)->value('qty'));
        $this->assertDatabaseHas('issued_documents', ['source_type' => 'purchase_return', 'source_id' => $response->json('data.id')]);
        $this->postJson($url, array_replace($payload, ['items' => [['purchase_item_id' => $line->id, 'qty' => '999']]]), $headers + ['Idempotency-Key' => 'too-many'])->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['expected_revision' => 999]), $headers + ['Idempotency-Key' => 'stale'])->assertConflict();
        $this->postJson($url, array_replace($payload, ['refund_amount' => '999.00']), $headers + ['Idempotency-Key' => 'over-refund'])->assertUnprocessable();
        $stock = DB::table('stock_balances')->where('product_id', $line->product_id)->first();
        DB::table('stock_balances')->where('id', $stock->id)->update(['qty' => '0.000']);
        $this->postJson($url, $payload, $headers + ['Idempotency-Key' => 'short-stock'])->assertUnprocessable();
        DB::table('stock_balances')->where('id', $stock->id)->update(['qty' => $stock->qty]);
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->postJson('/api/v1/suppliers/'.$purchase->supplier_id.'/payments', ['amount' => '204.20', 'mode' => 'bank'],
            $headers + ['Idempotency-Key' => 'pay-all'])->assertCreated();
        $paidReturn = $this->postJson($url, array_replace($payload, ['refund_amount' => '11.80']),
            $headers + ['Idempotency-Key' => 'paid-return'])->assertCreated()->assertJsonPath('data.refund_amount', '11.80');
        $this->getJson('/api/v1/suppliers/'.$purchase->supplier_id.'/ledger', $headers)->assertOk()->assertJsonPath('meta.balance', '0.00');
        $this->assertDatabaseHas('payments', ['id' => DB::table('purchase_returns')->where('id', $paidReturn->json('data.id'))->value('refund_payment_id'),
            'direction' => 'in', 'amount' => '11.80', 'purpose' => 'supplier_refund']);
        $this->artisan('stock:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'paid_amount' => '94.40', 'balance_amount' => '0.00']);
        $correction = ['expected_revision' => $purchase->current_revision, 'reason' => 'Check retained return dependency', 'supplier_invoice_no' => 'Retained returns'];
        $this->postJson('/api/v1/purchases/'.$purchase->id.'/revision-preview', $correction, $headers)
            ->assertOk()->assertJsonPath('data.after.balance_amount', '0.00')->assertJsonPath('data.after.paid_amount', '94.40');
        $this->postJson('/api/v1/purchases/'.$purchase->id.'/revision-preview', $correction + ['action' => 'soft_delete'], $headers)->assertUnprocessable();
        $this->withoutVite();
        $this->actingAs($owner, 'web')->withSession(['device_id' => $pair['device']->id, 'tenant_id' => $owner->tenant_id, 'workspace.branch' => $branch->id]);
        $this->get('/workspace/purchases/'.$purchase->id)->assertOk()->assertSee('Return goods to supplier')->assertSee($paidReturn->json('data.document_no'));
        $form = $payload + ['key' => (string) \Illuminate\Support\Str::uuid()];
        $this->from('/workspace/purchases/'.$purchase->id)->post('/workspace/purchases/'.$purchase->id.'/returns/preview', $form)
            ->assertRedirect('/workspace/purchases/'.$purchase->id)->assertSessionHas('purchase.return_preview');
        $this->assertDatabaseCount('purchase_returns', 2);
        $this->post('/workspace/purchases/'.$purchase->id.'/returns', $form)->assertRedirect('/workspace/purchases/'.$purchase->id);
        $this->assertDatabaseCount('purchase_returns', 3);
        $this->getJson('/api/v1/reports/gst-sales', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/documents-issued?document_type=purchase_return', $headers)->assertOk()->assertJsonPath('data.0.total', 3);
    }
}
