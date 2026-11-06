<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CompletedSaleTest extends TestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['migrations', 'gst_states'];

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_completed_sales_reconcile_with_series_stock_and_customer_settlement(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $branch->forceFill(['state_code' => '36'])->save();
        $tenant = $branch->tenant_id;
        $other = Branch::forceCreate(['tenant_id' => $tenant, 'code' => 'B02', 'name' => 'Second', 'state_code' => '36']);
        $owner = User::factory()->create(['tenant_id' => $tenant, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Sale test', [$branch->id, $other->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $otherHeaders = array_replace($headers, ['X-Branch-Id' => $other->id]);
        DB::table('shop_settings')->insert(['tenant_id' => $tenant, 'name' => 'Sale shop', 'gst_registration_type' => 'unregistered', 'allow_negative_stock' => false]);
        $unit = DB::table('units')->insertGetId(['tenant_id' => $tenant, 'code' => 'PCS', 'name' => 'Piece']);
        $rate = DB::table('tax_rates')->insertGetId(['tenant_id' => $tenant, 'name' => 'Zero', 'rate_bp' => 0]);
        $product = Product::forceCreate(['tenant_id' => $tenant, 'sku' => 'SALE', 'name' => 'Book', 'unit_id' => $unit, 'tax_rate_id' => $rate,
            'retail_price' => '100.00', 'wholesale_price' => '80.00', 'purchase_price' => '30.00', 'tax_inclusive' => false]);
        $year = ShopCalendar::financialYear(now());
        $series = ['financial_year' => $year, 'document_type' => 'commercial_bill', 'owning_channel' => 'web'];
        $this->assertSame('25-26', ShopCalendar::financialYear(new \DateTimeImmutable('2026-03-31T18:29:59Z')));
        $this->assertSame('26-27', ShopCalendar::financialYear(new \DateTimeImmutable('2026-03-31T18:30:00Z')));
        $this->postJson('/api/v1/document-series', $series + ['prefixes' => ['I']], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/document-series', array_replace($series, ['document_type' => 'debit_note', 'prefixes' => ['D']]), $headers)->assertUnprocessable();
        $this->postJson('/api/v1/document-series', array_replace($series, ['document_type' => 'credit_note']), $headers)
            ->assertCreated()->assertJsonPath('data.2.prefix', 'Z');
        $this->postJson('/api/v1/document-series', array_replace($series, ['owning_channel' => 'mobile', 'prefixes' => ['C']]), $headers)->assertCreated();
        $this->postJson('/api/v1/document-series', $series + ['prefixes' => ['A', 'B']], $headers)->assertCreated();
        $this->postJson('/api/v1/document-series', $series + ['prefixes' => ['A']], $headers)->assertConflict();
        $this->postJson('/api/v1/document-series', $series + ['prefixes' => ['A']], $otherHeaders)->assertCreated();
        DB::table('document_series')->where(['branch_id' => $branch->id, 'prefix' => 'A'])->update(['next_number' => 99999]);
        $cart = ['items' => [['product_id' => $product->id, 'qty' => '1']]];
        $calculate = fn (array $cart, array $auth) => $this->postJson('/api/v1/sales/calculate', $cart, $auth)->assertOk()->json('data.pricing_fingerprint');
        $post = fn (array $data, string $key, array $auth) => $this->postJson('/api/v1/sales', $data, $auth + ['Idempotency-Key' => $key]);
        $payload = $cart + ['acknowledged_pricing_fingerprint' => $calculate($cart, $headers), 'payments' => [['mode' => 'cash', 'amount' => '100.00']]];
        $this->assertDatabaseCount('issued_documents', 0);
        $product->forceFill(['retail_price' => '101.00'])->save();
        $post($payload, 'stale', $headers)->assertConflict()->assertJsonPath('code', 'PRICE_CHANGED');
        $product->forceFill(['retail_price' => '100.00'])->save();
        $post($cart + ['acknowledged_pricing_fingerprint' => $payload['acknowledged_pricing_fingerprint']], 'walk-in-credit', $headers)->assertUnprocessable();
        $post($payload, 'strict', $headers)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('issued_documents', 0);
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['allow_negative_stock' => true]);
        $hold = $post($cart + ['status' => 'held'], 'hold-first', $headers)->assertCreated()
            ->assertJsonPath('data.pricing.totals.total', '100.00')->json('data.id');
        $this->getJson('/api/v1/sales/held', $headers)->assertOk()->assertJsonPath('data.0.id', $hold);
        $this->getJson('/api/v1/sales/held', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('issued_documents', 0);
        $this->assertDatabaseCount('stock_ledger', 0);
        $this->assertDatabaseCount('payments', 0);
        $payload['held_sale_id'] = $hold;
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['allow_negative_stock' => false]);
        $post($payload, 'held-no-stock', $headers)->assertUnprocessable();
        $this->assertDatabaseHas('sales', ['id' => $hold, 'status' => 'held', 'issued_at' => null]);
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['allow_negative_stock' => true]);
        $product->forceFill(['retail_price' => '101.00'])->save();
        $post($payload, 'held-stale', $headers)->assertConflict()->assertJsonPath('code', 'PRICE_CHANGED');
        $this->assertDatabaseHas('sales', ['id' => $hold, 'status' => 'held', 'issued_at' => null]);
        $product->forceFill(['retail_price' => '100.00'])->save();
        $receipt = $post($payload + ['total' => '1.00', 'channel' => 'mobile'], 'first', $headers)->assertCreated()
            ->assertJsonPath('data.invoice_no', "MN1/$year/A99999")->assertJsonPath('data.totals.total', '100.00')
            ->assertJsonPath('data.items.0.cost.cost_estimated', true)->assertJsonPath('data.items.0.cost.cost_source', 'product_purchase');
        $receipt->assertJsonPath('data.id', $hold);
        $this->assertDatabaseHas('sales', ['id' => $hold, 'status' => 'completed', 'held_pricing_fingerprint' => $payload['acknowledged_pricing_fingerprint']]);
        $post($payload, 'second-completion', $headers)->assertConflict()->assertJsonPath('code', 'DOCUMENT_FINALIZED');
        $post($payload, 'first', $headers)->assertCreated()->assertExactJson($receipt->json());
        unset($payload['held_sale_id']);
        $this->assertDatabaseHas('document_series', ['branch_id' => $branch->id, 'prefix' => 'A', 'status' => 'exhausted', 'next_number' => 100000]);
        $this->assertDatabaseHas('document_series', ['branch_id' => $branch->id, 'prefix' => 'C', 'next_number' => 1]);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Credit buyer', 'credit_limit' => '90.00'], $headers)->assertCreated()->json('data.id');
        $creditCart = $cart + ['customer_id' => $customer, 'sale_type' => 'wholesale'];
        $credit = $creditCart + ['acknowledged_pricing_fingerprint' => $calculate($creditCart, $headers),
            'payments' => [['mode' => 'cash', 'amount' => '10.00'], ['mode' => 'upi', 'amount' => '10.00']]];
        $url = "/api/v1/customers/$customer";
        $sale = $post($credit, 'credit', $headers)->assertCreated()->assertJsonPath('data.invoice_no', "MN1/$year/B00001")
            ->assertJsonPath('data.balance_amount', '60.00')->json('data.id');
        $credit['acknowledged_pricing_fingerprint'] = $calculate($creditCart, $otherHeaders);
        $post($credit, 'across-branches', $otherHeaders)->assertConflict()->assertJsonPath('code', 'CREDIT_LIMIT_EXCEEDED');
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '60.00');
        $invoiceUrl = "/api/v1/sales/$sale/invoice";
        $beforeInvoice = $this->getJson($invoiceUrl, $headers)->assertOk()
            ->assertJsonPath('data.settlement.balance_amount', '60.00')->json('data');
        $this->getJson($invoiceUrl, $otherHeaders)->assertNotFound();
        $product->forceFill(['name' => 'Renamed book', 'retail_price' => '123.00'])->save();
        DB::table('customers')->where('id', $customer)->update(['name' => 'Renamed buyer']);
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['name' => 'Renamed shop']);
        $this->postJson("$url/receipts", ['mode' => 'cash', 'amount' => '15.00'], $headers + ['Idempotency-Key' => 'collection'])
            ->assertCreated()->assertJsonPath('data.allocations.0.sale_id', $sale);
        $this->getJson("/api/v1/sales/$sale", $headers)->assertOk()->assertJsonPath('data.balance_amount', '45.00');
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '45.00');
        $afterInvoice = $this->getJson($invoiceUrl, $headers)->assertOk()
            ->assertJsonPath('data.settlement.balance_amount', '45.00')
            ->assertJsonPath('data.settlement.paid_amount', '35.00')
            ->assertJsonCount(3, 'data.settlement.payments')->json('data');
        unset($beforeInvoice['settlement'], $afterInvoice['settlement']);
        $this->assertSame($beforeInvoice, $afterInvoice);
        $product->forceFill(['name' => 'Book', 'retail_price' => '100.00'])->save();
        $report = fn (string $path) => $this->getJson('/api/v1/'.$path, $headers)->assertOk();
        $report('reports/daily-sales')->assertJsonPath('data.0.net', '180.00')
            ->assertJsonPath('data.0.credit', '60.00')->assertJsonPath('data.0.collected_by_mode.cash', '125.00')
            ->assertJsonPath('data.0.collected_by_mode.upi', '10.00');
        $report('reports/sales-by-product')->assertJsonPath('data.0.total', '180.00')
            ->assertJsonPath('data.0.cost', '60.00')->assertJsonPath('data.0.qty', '2.000');
        $report('reports/sales-by-cashier')->assertJsonPath('data.0.cashier_id', $owner->id)->assertJsonPath('data.0.total', '180.00');
        $report('reports/stock-valuation')->assertJsonPath('data.0.qty', '-2.000')->assertJsonPath('data.0.value', '-60.00');
        $report('reports/low-stock')->assertJsonPath('data.0.product_id', $product->id);
        $report('reports/customer-outstanding')->assertJsonPath('data.0.balance', '45.00')
            ->assertJsonPath('data.0.opening_balance_incomplete', 1);
        $report('reports/supplier-outstanding')->assertJsonCount(0, 'data');
        $report('reports/payments')->assertJsonCount(4, 'data');
        $report('reports/profit')->assertJsonPath('data.gross_profit', '120.00')->assertJsonPath('data.estimated_cogs', '60.00');
        $report('dashboard')->assertJsonPath('data.sales.total', '180.00')->assertJsonPath('data.customer_dues.outstanding', '45.00');
        $this->getJson('/api/v1/dashboard', $otherHeaders)->assertOk()->assertJsonPath('data.sales.total', '0.00');
        $report('reports/daily-sales?from=2020-01-01&to=2020-01-01')->assertJsonCount(0, 'data');
        $issuedAt = DB::table('sales')->where('id', $hold)->value('issued_at');
        DB::table('sales')->where('id', $hold)->update(['issued_at' => '2025-04-01 18:30:00']);
        $report('reports/daily-sales?from=2025-04-01')->assertJsonCount(0, 'data');
        $report('reports/daily-sales?from=2025-04-02')->assertJsonPath('data.0.net', '100.00');
        DB::table('sales')->where('id', $hold)->update(['issued_at' => $issuedAt]);
        $openingCredit = $this->postJson("$url/opening-entries", [
            'entry_type' => 'credit', 'amount' => '10.00', 'effective_at' => '2020-02-01', 'reason' => 'Legacy credit',
        ], $headers + ['Idempotency-Key' => 'old-credit'])->assertCreated()->json('data.id');
        $allocation = $this->postJson("$url/opening-entries/$openingCredit/apply-credit", [
            'sale_id' => $sale, 'amount' => '10.00',
        ], $headers + ['Idempotency-Key' => 'apply-old-credit'])->assertCreated()->json('data.allocation_id');
        $this->getJson("/api/v1/sales/$sale", $headers)->assertOk()->assertJsonPath('data.balance_amount', '35.00');
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '35.00');
        $this->postJson("$url/opening-entries/$openingCredit/credit-allocations/$allocation/reverse", ['reason' => 'Undo allocation'],
            $headers + ['Idempotency-Key' => 'unapply-old-credit'])->assertCreated();
        $this->getJson("/api/v1/sales/$sale", $headers)->assertOk()->assertJsonPath('data.balance_amount', '45.00');
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '35.00');
        $this->assertDatabaseCount('issued_documents', 2);
        $this->assertDatabaseCount('stock_ledger', 2);
        $this->artisan('stock:reconcile')->assertSuccessful();

        $failure = null;
        DB::listen(function ($query) use (&$failure) {
            if ($failure && str_starts_with($query->sql, $failure)) {
                throw new \RuntimeException('Simulated sale posting failure');
            }
        });
        foreach (['stock_ledger', 'issued_documents', 'payment_allocations'] as $table) {
            $failure = 'insert into `'.$table.'`';
            $post($payload, 'fail-'.$table, $headers)->assertStatus(500);
            $failure = null;
            $this->assertDatabaseCount('sales', 2);
            $this->assertDatabaseCount('issued_documents', 2);
            $this->assertDatabaseCount('stock_ledger', 2);
            $this->assertDatabaseCount('payments', 4);
            $this->assertDatabaseHas('document_series', ['branch_id' => $branch->id, 'prefix' => 'B', 'next_number' => 2]);
        }

        $secondPair = app(DeviceEnrollment::class)->register($owner, 'Second terminal', [$branch->id, $other->id]);
        $this->app['auth']->forgetGuards();
        $secondToken = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $secondPair['pairing_code'],
        ])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/stock/adjustments', ['reason' => 'One remaining test unit', 'items' => [
            ['product_id' => $product->id, 'qty_change' => '3', 'unit_cost' => '30.00'],
        ]], $headers + ['Idempotency-Key' => 'restock'])->assertCreated();
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['allow_negative_stock' => false]);
        $jobs = [
            ['token' => $token, 'branch' => $branch->id, 'key' => 'strict-a', 'data' => $payload],
            ['token' => $secondToken, 'branch' => $branch->id, 'key' => 'strict-b', 'data' => $payload],
        ];
        $results = $this->race($jobs, 'branches', $branch->id);
        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $branch->id, 'product_id' => $product->id, 'qty' => '0.000']);
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['allow_negative_stock' => true]);
        $jobs[0]['key'] = 'negative-a';
        $jobs[1]['key'] = 'negative-b';
        $results = $this->race($jobs, 'branches', $branch->id);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertEqualsCanonicalizing(["MN1/$year/B00003", "MN1/$year/B00004"],
            array_map(fn ($result) => $result['body']['data']['invoice_no'], $results));
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $branch->id, 'product_id' => $product->id, 'qty' => '-2.000']);

        $raceCustomer = $this->postJson('/api/v1/customers', ['name' => 'Concurrent buyer', 'credit_limit' => '100.00'], $headers)->assertCreated()->json('data.id');
        $raceCart = $cart + ['customer_id' => $raceCustomer, 'sale_type' => 'wholesale'];
        $results = $this->race([
            ['token' => $token, 'branch' => $branch->id, 'key' => 'credit-a',
                'data' => $raceCart + ['acknowledged_pricing_fingerprint' => $calculate($raceCart, $headers)]],
            ['token' => $secondToken, 'branch' => $other->id, 'key' => 'credit-b',
                'data' => $raceCart + ['acknowledged_pricing_fingerprint' => $calculate($raceCart, $otherHeaders)]],
        ], 'customers', $raceCustomer);
        $this->assertEqualsCanonicalizing([201, 409], array_column($results, 'status'));
        $this->assertContains('CREDIT_LIMIT_EXCEEDED', array_map(fn ($result) => $result['body']['code'] ?? null, $results));
        $this->assertSame(1, DB::table('sales')->where('customer_id', $raceCustomer)->count());
        $this->artisan('stock:reconcile')->assertSuccessful();
        $raceHold = $post($cart + ['status' => 'held'], 'hold-race', $headers)->assertCreated()->json('data.id');
        $completion = $payload + ['held_sale_id' => $raceHold];
        $results = $this->race([
            ['token' => $token, 'branch' => $branch->id, 'key' => 'held-race-a', 'data' => $completion],
            ['token' => $secondToken, 'branch' => $branch->id, 'key' => 'held-race-b', 'data' => $completion],
        ], 'sales', $raceHold);
        $this->assertEqualsCanonicalizing([201, 409], array_column($results, 'status'));
        foreach ($results as $result) {
            if ($result['status'] === 201) {
                $this->assertSame($raceHold, $result['body']['data']['id']);
            } else {
                $this->assertSame('DOCUMENT_FINALIZED', $result['body']['code']);
            }
        }
        $discarded = $post($cart + ['status' => 'held'], 'hold-discard', $headers)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/sales/$discarded", ['reason' => 'Customer left'], $headers + ['Idempotency-Key' => 'discard'])
            ->assertOk()->assertJsonPath('data.status', 'discarded');
        $this->assertDatabaseHas('sales', ['id' => $discarded, 'status' => 'discarded', 'invoice_no' => null]);
        $this->getJson('/api/v1/sales/held', $headers)->assertOk()->assertJsonCount(0, 'data');
        $beforeReturnNet = $report('reports/daily-sales')->json('data.0.net');
        $product->forceFill(['retail_price' => '10.00'])->save();
        $returnCart = ['items' => [['product_id' => $product->id, 'qty' => '3']],
            'bill_discount' => ['type' => 'amount', 'value' => '0.01']];
        $returnSale = $post($returnCart + ['acknowledged_pricing_fingerprint' => $calculate($returnCart, $headers),
            'payments' => [['mode' => 'cash', 'amount' => '29.99']]], 'sale-for-return', $headers)->assertCreated()->json('data');
        $product->forceFill(['retail_price' => '20.00'])->save();
        $returnUrl = '/api/v1/sales/'.$returnSale['id'].'/returns';
        $returnPayload = ['reason' => 'Returned unit', 'refund_mode' => 'cash',
            'items' => [['sale_item_id' => $returnSale['items'][0]['id'], 'qty' => '1']]];
        $failure = 'insert into `issued_documents`';
        $this->postJson($returnUrl, $returnPayload, $headers + ['Idempotency-Key' => 'failed-note'])->assertStatus(500);
        $failure = null;
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame(0, DB::table('stock_ledger')->where('type', 'sale_return')->count());
        $this->assertSame(0, DB::table('payments')->where('purpose', 'refund')->count());
        $this->assertDatabaseHas('document_series', ['branch_id' => $branch->id, 'prefix' => 'X', 'next_number' => 1]);
        foreach (['10.00', '9.99', '10.00'] as $part => $expected) {
            $auth = $headers + ['Idempotency-Key' => 'return-'.$part];
            $note = $this->postJson($returnUrl, $returnPayload, $auth)->assertCreated()
                ->assertJsonPath('data.totals.total', $expected)->assertJsonPath('data.refund', $expected)->json('data');
            $this->postJson($returnUrl, $returnPayload, $auth)->assertCreated()->assertJsonPath('data.id', $note['id']);
            $this->getJson('/api/v1/sale-returns/'.$note['id'].'/invoice', $headers)->assertOk()
                ->assertJsonPath('data.original_invoice_no', $returnSale['invoice_no']);
        }
        $this->postJson($returnUrl, $returnPayload, $headers + ['Idempotency-Key' => 'over-return'])->assertUnprocessable();
        $this->assertSame('29.99', (string) DB::table('sale_returns')->where('sale_id', $returnSale['id'])->sum('total'));
        $this->assertSame('29.99', (string) DB::table('payments')->where('purpose', 'refund')->sum('amount'));
        $report('reports/daily-sales')->assertJsonPath('data.0.net', $beforeReturnNet);
        $product->forceFill(['retail_price' => '100.00'])->save();
        $this->postJson('/api/v1/sales/'.$returnSale['id'].'/cancel', ['reason' => 'Already returned'],
            $headers + ['Idempotency-Key' => 'cancel-returned-sale'])->assertConflict();
        $this->postJson('/api/v1/stock/adjustments', ['reason' => 'Stock available for reversal', 'items' => [
            ['product_id' => $product->id, 'qty_change' => '20', 'unit_cost' => '30.00'],
        ]], $headers + ['Idempotency-Key' => 'return-reversal-stock'])->assertCreated();
        $noteCancel = '/api/v1/sale-returns/'.$note['id'].'/cancel';
        $this->postJson($noteCancel, ['reason' => 'Refund recovered'], $headers + ['Idempotency-Key' => 'recover-refund'])->assertUnprocessable();
        $this->postJson($noteCancel, ['reason' => 'Refund recovered', 'refund_recovered' => true],
            $headers + ['Idempotency-Key' => 'recover-refund'])->assertOk()->assertJsonPath('data.invoice_no', $note['invoice_no']);
        $this->postJson($noteCancel, ['reason' => 'Duplicate reversal', 'refund_recovered' => true],
            $headers + ['Idempotency-Key' => 'recover-refund-again'])->assertConflict();
        $this->assertDatabaseHas('sale_returns', ['id' => $note['id'], 'status' => 'cancelled']);

        $this->postJson("/api/v1/sales/$hold/cancel", ['reason' => 'Walk-in refunded', 'refund_mode' => 'credit_adjust'],
            $headers + ['Idempotency-Key' => 'walk-in-cancel-credit'])->assertUnprocessable();
        $this->postJson("/api/v1/sales/$hold/cancel", ['reason' => 'Walk-in refunded', 'refund_mode' => 'cash'],
            $headers + ['Idempotency-Key' => 'walk-in-cancel'])->assertOk()->assertJsonPath('data.refund', '100.00')
            ->assertJsonPath('data.invoice_no', "MN1/$year/A99999");
        $this->postJson("/api/v1/sales/$hold/cancel", ['reason' => 'Duplicate cancellation', 'refund_mode' => 'cash'],
            $headers + ['Idempotency-Key' => 'walk-in-cancel-again'])->assertConflict();
        $this->assertDatabaseHas('issued_documents', ['document_no' => "MN1/$year/A99999", 'cancellation_reason' => 'Walk-in refunded']);

        $this->postJson("$url/opening-entries/$openingCredit/apply-credit", ['sale_id' => $sale, 'amount' => '10.00'],
            $headers + ['Idempotency-Key' => 'apply-credit-before-cancel'])->assertCreated();
        $beforeComment = $this->getJson($invoiceUrl, $headers)->assertOk()->json('data');
        $this->patchJson("/api/v1/sales/$sale/comment", ['note' => 'Deliver after 5 PM', 'reason' => 'Customer requested'],
            $headers + ['Idempotency-Key' => 'invoice-comment'])->assertOk();
        $afterComment = $this->getJson($invoiceUrl, $headers)->assertOk()->assertJsonPath('data.note', 'Deliver after 5 PM')->json('data');
        $beforeComment['note'] = 'Deliver after 5 PM';
        $this->assertSame($beforeComment, $afterComment);
        $this->getJson("/api/v1/sales/$sale/comment-history", $headers)->assertOk()
            ->assertJsonPath('data.0.before', null)->assertJsonPath('data.0.after', 'Deliver after 5 PM');
        $this->postJson("/api/v1/sales/$sale/cancel", ['reason' => 'Keep paid funds as credit', 'refund_mode' => 'credit_adjust'],
            $headers + ['Idempotency-Key' => 'customer-cancel'])->assertOk()
            ->assertJsonPath('data.refund', '0.00')->assertJsonPath('data.retained_credit', '20.00');
        $this->assertSame('0.00', (string) DB::table('party_credit_allocations')->where('sale_id', $sale)->sum('amount'));
        $this->assertSame('0.00', (string) DB::table('payment_allocations')->where('sale_id', $sale)->sum('amount'));
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '-45.00');
        $this->artisan('stock:reconcile')->assertSuccessful();
        $unpaid = $post($creditCart + ['acknowledged_pricing_fingerprint' => $calculate($creditCart, $headers)],
            'unpaid-sale', $headers)->assertCreated()->assertJsonPath('data.balance_amount', '80.00')->json('data.id');
        $this->postJson("/api/v1/sales/$unpaid/cancel", ['reason' => 'Unpaid sale cancelled'],
            $headers + ['Idempotency-Key' => 'unpaid-cancel'])->assertOk()->assertJsonPath('data.refund', '0.00')
            ->assertJsonPath('data.refund_payment_id', null);
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '-45.00');
        $debtSale = $post($creditCart + ['acknowledged_pricing_fingerprint' => $calculate($creditCart, $headers)],
            'debt-sale', $headers)->assertCreated()->json('data');
        $debtNote = $this->postJson('/api/v1/sales/'.$debtSale['id'].'/returns', ['reason' => 'Unpaid goods returned',
            'items' => [['sale_item_id' => $debtSale['items'][0]['id'], 'qty' => '1']]],
            $headers + ['Idempotency-Key' => 'debt-return'])->assertCreated()->assertJsonPath('data.credit_adjusted', '80.00')
            ->assertJsonPath('data.refund', '0.00')->json('data.id');
        $this->assertDatabaseHas('sales', ['id' => $debtSale['id'], 'balance_amount' => '0.00']);
        $this->postJson("/api/v1/sale-returns/$debtNote/cancel", ['reason' => 'Goods retained'],
            $headers + ['Idempotency-Key' => 'debt-return-reversed'])->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $debtSale['id'], 'balance_amount' => '80.00']);
        $this->getJson("$url/ledger", $headers)->assertOk()->assertJsonPath('meta.balance', '35.00');
        DB::table('document_series')->where(['branch_id' => $branch->id, 'prefix' => 'B'])->update(['status' => 'exhausted', 'next_number' => 100000]);
        $post($payload, 'exhausted', $headers)->assertConflict()->assertJsonPath('code', 'DOCUMENT_SERIES_EXHAUSTED');
        $branch->forceFill(['gstin' => '36ABCDE1234F1Z5'])->save();
        DB::table('shop_settings')->where('tenant_id', $tenant)->update(['gst_registration_type' => 'regular']);
        $missing = array_replace($payload, ['acknowledged_pricing_fingerprint' => $calculate($cart, $headers)]);
        $post($missing, 'missing-type', $headers)->assertConflict()->assertJsonPath('code', 'DOCUMENT_SERIES_UNAVAILABLE');
        $this->assertDatabaseHas('document_series', ['branch_id' => $branch->id, 'prefix' => 'C', 'next_number' => 1]);
        $this->artisan('stock:reconcile')->assertSuccessful();

        $cashier = User::factory()->create(['tenant_id' => $tenant, 'password' => 'test-password-123']);
        $cashier->assignRole('Cashier');
        $cashier->branches()->attach($branch, ['tenant_id' => $tenant]);
        $cashierPair = app(DeviceEnrollment::class)->register($owner, 'Cashier terminal', [$branch->id]);
        $this->app['auth']->forgetGuards();
        $cashierToken = $this->postJson('/api/v1/auth/token', [
            'email' => $cashier->email, 'password' => 'test-password-123', 'pairing_code' => $cashierPair['pairing_code'],
        ])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $cashierHeaders = array_replace($headers, ['Authorization' => 'Bearer '.$cashierToken]);
        $this->getJson('/api/v1/reports/daily-sales', $cashierHeaders)->assertOk()->assertJsonCount(0, 'data');
        DB::table('sales')->where('id', $raceHold)->update(['cashier_id' => $cashier->id]);
        $this->getJson('/api/v1/reports/daily-sales', $cashierHeaders)->assertOk()->assertJsonPath('data.0.net', '100.00');
        $this->getJson('/api/v1/reports/daily-sales', array_replace($cashierHeaders, ['X-Branch-Id' => $other->id]))->assertNotFound();
        $this->getJson('/api/v1/reports/profit', $cashierHeaders)->assertForbidden();
        $this->getJson('/api/v1/dashboard', $cashierHeaders)->assertForbidden();
    }

    private function race(array $jobs, string $lockTable, int $lockId): array
    {
        $workers = array_map(fn (array $job) => new Process([PHP_BINARY, base_path('tests/Support/submit-sale.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'khatapos_test', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ], json_encode($job, JSON_THROW_ON_ERROR), 40), $jobs);

        try {
            DB::transaction(function () use ($workers, $lockTable, $lockId) {
                DB::table($lockTable)->where('id', $lockId)->lockForUpdate()->first();
                foreach ($workers as $worker) {
                    $worker->start();
                }
                foreach ($workers as $worker) {
                    $this->assertTrue($worker->waitUntil(fn (string $type, string $output) => $type === Process::ERR && str_contains($output, 'ready')));
                }
            });

            return array_map(function (Process $worker) {
                $this->assertSame(0, $worker->wait());

                return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }
}
