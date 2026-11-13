<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReportExportTest extends PurchasePostingTest
{
    public function test_workbook_matches_filtered_json_and_enforces_permission_and_row_cap(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $owner = User::role('Owner')->firstOrFail();
        $branch = Branch::firstOrFail();
        $pair = app(DeviceEnrollment::class)->register($owner, 'Report exports', [$branch->id]);
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $product = Product::firstOrFail();
        $product->forceFill(['name' => '=HYPERLINK("https://example.test","Do not execute")'])->save();
        $expected = $this->getJson('/api/v1/reports/stock-valuation', $headers)->assertOk()->json('data.0');
        config(['excel.cache.batch.memory_limit' => 8]);
        $response = $this->get('/api/v1/reports/stock-valuation/export', $headers)->assertOk();
        $response->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getActiveSheet();
            $values = $sheet->toArray(null, false, false);
            $columns = array_keys($expected);
            $this->assertSame(array_values($expected), array_map(fn ($value, $index) => is_int($expected[$columns[$index]]) ? (int) $value : $value, $values[1], array_keys($values[1])));
            $nameColumn = array_search('name', $columns, true) + 1;
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell([$nameColumn, 2])->getDataType());
            $this->assertSame($expected['name'], $sheet->getCell([$nameColumn, 2])->getValue());
            $this->assertStringContainsString('exact text', $workbook->getProperties()->getDescription());
            $workbook->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        config(['excel.cache.batch.memory_limit' => 10000]);
        $other = Branch::forceCreate(['tenant_id' => $owner->tenant_id, 'code' => 'EXP', 'name' => 'Unpaired']);
        $this->getJson('/api/v1/reports/stock-valuation/export', array_replace($headers, ['X-Branch-Id' => $other->id]))->assertNotFound();
        $owner->syncRoles(['Cashier']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports/stock-valuation/export', $headers)->assertForbidden();
        $owner->syncRoles(['Owner']);
        $this->app['auth']->forgetGuards();
        for ($offset = 0; $offset < 50000; $offset += 1000) {
            $rows = [];
            for ($index = $offset; $index < $offset + 1000; $index++) {
                $rows[] = ['tenant_id' => $owner->tenant_id, 'sku' => 'EXPORT-'.$index, 'name' => 'Export row '.$index,
                    'unit_id' => $product->unit_id, 'tax_rate_id' => $product->tax_rate_id, 'tax_inclusive' => false];
            }
            DB::table('products')->insert($rows);
            if ($offset === 0) {
                $chunked = $this->get('/api/v1/reports/stock-valuation/export', $headers)->assertOk();
                $chunkedPath = $chunked->baseResponse->getFile()->getPathname();
                try {
                    $chunkedBook = IOFactory::load($chunkedPath);
                    $this->assertSame(1002, $chunkedBook->getActiveSheet()->getHighestRow());
                    $exportedRows = $chunkedBook->getActiveSheet()->toArray(null, false, false);
                    $skuColumn = array_search('Sku', $exportedRows[0], true);
                    $this->assertSame(1001, count(array_unique(array_column(array_slice($exportedRows, 1), $skuColumn))));
                    $chunkedBook->disconnectWorksheets();
                } finally {
                    unlink($chunkedPath);
                }
                $this->assertSame([], glob(storage_path('framework/cache/export-*')));
            }
        }
        $this->getJson('/api/v1/reports/stock-valuation/export', $headers)->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->assertSame([], glob(storage_path('framework/cache/export-*')));
    }
}
