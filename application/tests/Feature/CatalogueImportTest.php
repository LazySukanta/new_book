<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CatalogueImportTest extends TestCase
{
    public function test_import_preflight_atomicity_and_repeated_price_updates(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Import', [$branch->id]);
        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code'],
        ])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $this->postJson('/api/v1/units', ['name' => 'Piece', 'code' => 'PCS'], $headers)->assertCreated();
        $this->postJson('/api/v1/tax-rates', ['name' => 'Fixture', 'rate_bp' => 0], $headers)->assertCreated();

        $header = "name,sku,barcode,unit,gst_rate,retail_price,wholesale_price\n";
        $valid = $header."Notebook,NB1,0001,PCS,0,100.00,90.00\nPen,PN1,0002,PCS,0,20.00,18.00\n";
        $invalid = $header."Notebook,NB1,0001,PCS,0,100.00,90.00\nInvalid,ZZ,0003,PCS,0,20.001,18.00\nPen,PN1,0002,PCS,0,20.00,18.00\n";
        $upload = fn (string $csv, string $query = '') => $this->post('/api/v1/products/import'.$query, [
            'file' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv),
        ], $headers);

        $upload($valid, '?dry_run=1')->assertOk()->assertJsonPath('data.written', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $upload($invalid)->assertUnprocessable()->assertJsonValidationErrors('rows.3.amount');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product.pricing']);
        $upload(str_replace('PN1,0002', 'NB1,0002', $valid))->assertUnprocessable()->assertJsonValidationErrors('rows.3.sku');
        $upload(str_replace('PN1,0002', 'ŃB1,0002', $valid), '?dry_run=1')->assertUnprocessable()->assertJsonValidationErrors('rows.3.sku');
        $upload(str_replace('PN1,0002', 'PN1,0001', $valid))->assertUnprocessable()->assertJsonValidationErrors('rows.3.barcode');
        $upload(str_replace('wholesale_price', 'opening_qty', $valid))->assertUnprocessable()->assertJsonValidationErrors('file');
        $upload($header.str_repeat("Notebook,NB1,0001,PCS,0,100.00,90.00\n", 2001))->assertUnprocessable()->assertJsonValidationErrors('file');
        $upload($valid)->assertOk()->assertJsonPath('data.written', 2);
        $upload(str_replace('100.00', '110.00', $valid))->assertOk();
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('stock_balances', 2);
        $this->assertDatabaseHas('products', ['sku' => 'NB1', 'retail_price' => '110.00']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.pricing', 'reason' => 'Product CSV import']);
        $this->assertDatabaseHas('stock_balances', ['qty' => '0.000']);
    }

    use RefreshDatabase;
}
