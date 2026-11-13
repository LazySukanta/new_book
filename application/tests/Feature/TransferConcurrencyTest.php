<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use App\Services\StockPosting;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\PurchaseFixture;
use Tests\TestCase;

class TransferConcurrencyTest extends TestCase
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

    public function test_two_terminals_dispatch_one_transfer_only_once(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $source = Branch::firstOrFail();
        $source->forceFill(['gstin' => '36ABCDE1234F1Z5'])->save();
        $destination = Branch::forceCreate(['tenant_id' => $source->tenant_id, 'code' => 'DST', 'name' => 'Destination', 'gstin' => $source->gstin]);
        $owner = User::factory()->create(['tenant_id' => $source->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Transfer race', [$source->id, $destination->id]);
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $source->id];
        $unit = DB::table('units')->insertGetId(['tenant_id' => $source->tenant_id, 'code' => 'PCS', 'name' => 'Piece']);
        $tax = DB::table('tax_rates')->insertGetId(['tenant_id' => $source->tenant_id, 'rate_bp' => 0, 'name' => 'Zero']);
        $product = Product::forceCreate(['tenant_id' => $source->tenant_id, 'sku' => 'RACE', 'name' => 'Race stock', 'unit_id' => $unit, 'tax_rate_id' => $tax, 'tax_inclusive' => false]);
        $item = PurchaseFixture::create($owner, $source->id, $product->id);
        app(StockPosting::class)->receive($owner, $source->id, $product->id, $item, '10', '4.00');
        $this->postJson('/api/v1/document-series', ['financial_year' => ShopCalendar::financialYear(now()), 'document_type' => 'stock_transfer',
            'owning_channel' => 'web', 'prefixes' => ['T']], $headers)->assertCreated();
        $id = $this->postJson('/api/v1/stock-transfers', ['destination_branch_id' => $destination->id,
            'items' => [['product_id' => $product->id, 'qty' => '10']]], $headers + ['Idempotency-Key' => 'draft-race'])->assertCreated()->json('data.id');
        $workers = [];
        foreach (['terminal-a', 'terminal-b'] as $key) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/submit-sale.php')], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'khatapos_test', 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            ], json_encode(['token' => $token, 'branch' => $source->id, 'key' => $key,
                'path' => '/api/v1/stock-transfers/'.$id.'/dispatch', 'data' => ['expected_version' => 1]], JSON_THROW_ON_ERROR), 60);
        }
        try {
            DB::transaction(function () use ($workers, $source) {
                DB::table('branches')->where('id', $source->id)->lockForUpdate()->first();
                foreach ($workers as $worker) {
                    $worker->start();
                }
                foreach ($workers as $worker) {
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => $type === Process::ERR && str_contains($output, 'ready')));
                }
            });
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $statuses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertDatabaseCount('issued_documents', 1);
            $this->assertDatabaseCount('stock_ledger', 2);
            $this->assertDatabaseHas('stock_balances', ['branch_id' => $source->id, 'product_id' => $product->id, 'qty' => '0.000', 'inventory_value' => '0.00']);
            $this->assertDatabaseHas('stock_transfer_items', ['transfer_id' => $id, 'qty_dispatched' => '10.000', 'dispatched_value' => '40.00']);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
        $transferItem = DB::table('stock_transfer_items')->where('transfer_id', $id)->value('id');
        $receipt = ['expected_version' => 2, 'items' => [['transfer_item_id' => $transferItem, 'qty' => '8']]];
        $this->assertSame([200, 409], $this->raceReceiving($token, $destination->id, '/api/v1/stock-transfers/'.$id.'/receive', $receipt, $source->id));
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $destination->id, 'product_id' => $product->id, 'qty' => '8.000', 'inventory_value' => '32.00']);
        $resolution = ['expected_version' => 3, 'transfer_item_id' => $transferItem, 'resolution' => 'written_off', 'qty' => '2', 'reason' => 'Lost in transit'];
        $this->assertSame([201, 409], $this->raceReceiving($token, $destination->id, '/api/v1/stock-transfers/'.$id.'/resolutions', $resolution, $source->id));
        $this->assertDatabaseCount('transfer_resolutions', 1);
        $this->assertDatabaseCount('issued_documents', 1);
        $this->assertDatabaseCount('stock_ledger', 3);
        $this->assertDatabaseHas('stock_transfer_items', ['transfer_id' => $id, 'qty_written_off' => '2.000', 'written_off_value' => '8.00']);
        $this->artisan('stock:reconcile')->assertSuccessful();
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Concurrent return supplier'], $headers)->assertCreated()->json('data.id');
        $purchase = $this->postJson('/api/v1/purchases', ['supplier_id' => $supplier, 'supplier_invoice_no' => 'RETURN-RACE',
            'supplier_invoice_date' => now('Asia/Kolkata')->toDateString(), 'cost_includes_tax' => false, 'tax_type' => 'intra', 'input_tax_recoverable' => false,
            'items' => [['product_id' => $product->id, 'qty' => '2', 'unit_cost' => '4.00']]], $headers + ['Idempotency-Key' => 'return-race-purchase'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/document-series', ['financial_year' => ShopCalendar::financialYear(now()), 'document_type' => 'purchase_return',
            'owning_channel' => 'web', 'prefixes' => ['R']], $headers)->assertCreated();
        $purchaseItem = DB::table('purchase_items')->where('purchase_id', $purchase)->value('id');
        $return = ['expected_revision' => 1, 'reason' => 'Damaged delivery', 'refund_amount' => '0.00', 'refund_mode' => 'cash',
            'items' => [['purchase_item_id' => $purchaseItem, 'qty' => '2']]];
        $this->assertSame([201, 422], $this->raceReceiving($token, $source->id, '/api/v1/purchases/'.$purchase.'/returns', $return, $source->id));
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertDatabaseCount('issued_documents', 2);
        $this->assertDatabaseHas('purchases', ['id' => $purchase, 'balance_amount' => '0.00']);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $source->id, 'product_id' => $product->id, 'qty' => '0.000', 'inventory_value' => '0.00']);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    private function raceReceiving(string $token, int $branch, string $path, array $data, int $lockBranch): array
    {
        $workers = [];
        foreach (['a', 'b'] as $terminal) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/submit-sale.php')], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'khatapos_test', 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            ], json_encode(['token' => $token, 'branch' => $branch, 'key' => 'race-'.basename($path).'-'.$terminal,
                'path' => $path, 'data' => $data], JSON_THROW_ON_ERROR), 60);
        }
        try {
            DB::transaction(function () use ($workers, $lockBranch) {
                DB::table('branches')->where('id', $lockBranch)->lockForUpdate()->first();
                foreach ($workers as $worker) {
                    $worker->start();
                }
                foreach ($workers as $worker) {
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => $type === Process::ERR && str_contains($output, 'ready')));
                }
            });
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $statuses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            return $statuses;
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }
}
