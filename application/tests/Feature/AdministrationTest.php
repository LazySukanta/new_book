<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbered_history_locks_branch_code_and_preserves_tax_snapshot(): void
    {
        [$owner, $branch, $device] = $this->setupShop();
        $headers = $this->headers($owner, $device);
        $url = '/api/v1/branches/'.$branch->id;
        $this->patchJson($url, ['code' => 'NEW'], $headers)->assertOk();
        $this->seed(ShopAccessSeeder::class);
        $this->assertDatabaseCount('branches', 1);
        $branch->forceFill(['state_code' => '36', 'gstin' => '36ABCDE1234F1Z5'])->save();
        $headers['X-Branch-Id'] = $branch->id;
        $unit = DB::table('units')->insertGetId(['tenant_id' => $owner->tenant_id, 'code' => 'PCS', 'name' => 'Piece']);
        $rate = DB::table('tax_rates')->insertGetId(['tenant_id' => $owner->tenant_id, 'name' => '18%', 'rate_bp' => 1800]);
        $product = Product::forceCreate(['tenant_id' => $owner->tenant_id, 'sku' => 'HISTORY', 'name' => 'Book', 'unit_id' => $unit,
            'tax_rate_id' => $rate, 'retail_price' => '118.00', 'tax_inclusive' => true]);
        $this->postJson('/api/v1/document-series', [
            'financial_year' => ShopCalendar::financialYear(now()), 'document_type' => 'tax_invoice', 'owning_channel' => 'web', 'prefixes' => ['A'],
        ], $headers)->assertCreated();
        $cart = ['items' => [['product_id' => $product->id, 'qty' => '1']]];
        $fingerprint = $this->postJson('/api/v1/sales/calculate', $cart, $headers)->assertOk()->json('data.pricing_fingerprint');
        $sale = $this->postJson('/api/v1/sales', $cart + [
            'acknowledged_pricing_fingerprint' => $fingerprint, 'payments' => [['mode' => 'cash', 'amount' => '118.00']],
        ], $headers + ['Idempotency-Key' => 'historical-sale'])->assertCreated()->json('data.id');
        $this->patchJson($url, ['code' => 'OLD'], $headers)->assertConflict()->assertJsonPath('code', 'BRANCH_CODE_LOCKED');
        $this->patchJson($url, ['name' => 'Renamed'], $headers)->assertOk()->assertJsonPath('data.code', 'NEW');
        $this->putJson('/api/v1/settings', ['gst_registration_type' => 'composition'], $headers)->assertOk();
        $this->getJson("/api/v1/sales/$sale", $headers)->assertOk()
            ->assertJsonPath('data.gst_registration_type', 'regular')->assertJsonPath('data.branch.name', $branch->name);
    }

    public function test_manager_can_manage_only_cashiers_in_assigned_branches(): void
    {
        [$owner, $main, $device] = $this->setupShop();
        $ownerHeaders = $this->headers($owner, $device);
        $second = $this->postJson('/api/v1/branches', ['name' => 'Second', 'code' => 'S2'], $ownerHeaders)->assertCreated()->json('data.id');
        $manager = $this->postJson('/api/v1/users', [
            'name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'password123',
            'role' => 'Manager', 'branch_ids' => [$main->id],
        ], $ownerHeaders)->assertCreated()->json('data.id');
        $managerHeaders = $this->headers(User::findOrFail($manager), $device);
        $cashier = ['name' => 'Cashier', 'email' => 'cashier@example.test', 'password' => 'password123',
            'role' => 'Cashier', 'branch_ids' => [$main->id]];
        $this->postJson('/api/v1/users', array_replace($cashier, ['role' => 'Owner']), $managerHeaders)->assertUnprocessable();
        $this->postJson('/api/v1/users', array_replace($cashier, ['branch_ids' => [$second]]), $managerHeaders)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/users/'.$manager, ['branch_ids' => [$second]], $managerHeaders)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/branches/'.$second, ['name' => 'Denied'], $managerHeaders)->assertForbidden();
        $cashierId = $this->postJson('/api/v1/users', $cashier, $managerHeaders)->assertCreated()->assertJsonMissingPath('data.password')->json('data.id');
        $cashierUser = User::findOrFail($cashierId);
        $cashierHeaders = $this->headers($cashierUser, $device);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/branch', $cashierHeaders + ['X-Branch-Id' => $main->id])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/users/'.$cashierId.'/reset-password', ['password' => 'different123'], $managerHeaders)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $cashierHeaders)->assertUnauthorized();
        $cashierHeaders = $this->headers($cashierUser, $device);
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/users/'.$cashierId.'/active', ['is_active' => false], $managerHeaders)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $cashierHeaders)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/branches/'.$main->id.'/active', ['is_active' => false], $ownerHeaders)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/branch', $managerHeaders + ['X-Branch-Id' => $main->id])->assertNotFound();
    }

    public function test_settings_validation_and_logo(): void
    {
        [$owner, $branch, $device] = $this->setupShop();
        $headers = $this->headers($owner, $device);
        Storage::fake('local');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/gst-states', $headers)->assertOk()->assertJsonFragment(['code' => '38', 'name' => 'Ladakh']);
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/branches/'.$branch->id, ['state_code' => '27', 'gstin' => '29ABCDE1234F1Z5'], $headers)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/settings', ['max_cashier_discount_bp' => 10001], $headers)->assertUnprocessable();
        $this->post('/api/v1/settings', ['logo' => UploadedFile::fake()->create('bad.txt')], $headers + ['Accept' => 'application/json'])->assertUnprocessable();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7s0AAAAASUVORK5CYII=');
        $path = $this->post('/api/v1/settings', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)], $headers)
            ->assertOk()->json('data.logo_path');
        Storage::disk('local')->assertExists($path);
        $this->get('/api/v1/settings/logo', $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/settings', ['allow_negative_stock' => false, 'gst_registration_type' => 'composition'], $headers)
            ->assertOk()->assertJsonPath('data.allow_negative_stock', 0)->assertJsonPath('data.gst_registration_type', 'composition');
    }

    private function setupShop(): array
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Admin', [$branch->id]);

        return [$owner, $branch, $pair['device']->id];
    }

    private function headers(User $user, int $device): array
    {
        $this->app['auth']->forgetGuards();
        $token = bin2hex(random_bytes(32));
        $row = $user->tokens()->create([
            'name' => 'test', 'token' => hash('sha256', $token), 'abilities' => ['*'], 'expires_at' => now()->addDay(),
            'tenant_id' => $user->tenant_id, 'device_id' => $device,
        ]);

        return ['Authorization' => 'Bearer '.$row->id.'|'.$token];
    }
}
