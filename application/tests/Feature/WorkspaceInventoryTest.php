<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_and_stock_forms_use_scoped_posting_and_fresh_previews(): void
    {
        $this->withoutVite();
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');
        $pair = app(DeviceEnrollment::class)->register($owner, 'Inventory browser', [$branch->id]);
        $this->post('/sign-in', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])
            ->assertRedirect('/workspace');
        $this->withCookie(config('session.cookie'), session()->getId());
        $root = '/workspace/inventory';
        $this->get("$root/references")->assertOk()->assertSee('Add unit');
        $this->post("$root/references/units", ['name' => 'Kilogram', 'code' => 'KG', 'allow_decimal' => 1])->assertRedirect();
        $this->post("$root/references/tax-rates", ['name' => 'Zero', 'rate_bp' => 0])->assertRedirect();
        $product = [
            'name' => str_repeat('Long product name ', 9), 'sku' => str_repeat('SKU', 30),
            'unit_id' => 1, 'tax_rate_id' => 1, 'tax_inclusive' => 0, 'retail_price' => '123456.70',
            'wholesale_price' => '100000.00', 'purchase_price' => '10.00', 'reorder_level' => '2.000',
        ];
        $this->post("$root/product", $product)->assertRedirect();
        $id = Product::firstOrFail()->id;
        $this->get("$root/products?search=SKU")->assertOk()->assertSee('1,23,456.70')->assertSee($product['sku']);
        $this->post("$root/product/$id", ['retail_price' => '123456.75', 'reason' => 'New season', 'manage_slabs' => 1,
            'slabs' => [['min_qty' => '2.500', 'price' => '90000.00'], ['min_qty' => '', 'price' => '']]])->assertRedirect();
        $this->get("$root/edit/$id")->assertOk()->assertSee('1,23,456.70')->assertSee('1,23,456.75')->assertSee('New season')->assertSee('90,000.00');

        $upload = fn (string $price, int $dry) => $this->from("$root/import")->post("$root/import", [
            'file' => UploadedFile::fake()->createWithContent('sample.csv', "name,sku,unit,gst_rate,retail_price,wholesale_price\nTea,T01,KG,0,$price,10.00\n"),
            'key' => (string) Str::uuid(), 'dry_run' => $dry, 'branch_id' => $branch->id,
        ]);
        $upload('10.001', 1)->assertRedirect()->assertSessionHasErrors('rows.2.amount');
        $this->get("$root/import")->assertOk()->assertSee('Row 2');
        $upload('11.00', 1)->assertRedirect()->assertSessionHas('inventory.message', '1 rows passed validation. Select the same CSV to import it.');
        $this->assertDatabaseCount('products', 1);
        $upload('11.00', 0)->assertRedirect()->assertSessionHas('inventory.message', '1 rows imported.');
        $this->assertDatabaseCount('products', 2);

        $movement = "$root/movement/$id";
        $intent = ['kind' => 'count_correction', 'product_id' => $id, 'quantity' => '1.250', 'unit_cost' => '10.00', 'reason' => 'Counted'];
        $preview = fn (array $data) => $this->from($movement)->post("$root/movement/preview", $data)->assertRedirect($movement);
        $preview($intent + ['date' => '2020-01-01'])->assertSessionHasErrors('date');
        $preview($intent)->assertSessionHas('inventory.preview.qty_after', '1.250')
            ->assertSessionHas('inventory.preview.value_after', '12.50')->assertSessionHas('inventory.preview.avg_cost_after', '10.000000000');
        $stale = session('inventory.token');
        $this->get($movement)->assertOk()->assertSee('Review stock movement');
        $preview(array_replace($intent, ['kind' => 'adjustment', 'quantity' => '0.500']));
        $adjustment = session('inventory.token');
        $this->post("$root/movement/post", ['preview_token' => $adjustment])->assertRedirect();
        $this->from($movement)->post("$root/movement/post", ['preview_token' => $stale])->assertRedirect($movement)->assertSessionHasErrors('preview');
        $this->assertDatabaseCount('stock_ledger', 1);
        $this->get($movement)->assertOk()->assertSee('Stock changed')->assertDontSee('Post movement');
        $preview($intent)->assertSessionHas('inventory.preview.qty_before', '0.500');
        $fresh = session('inventory.token');
        $this->post("$root/movement/post", ['preview_token' => $fresh])->assertRedirect();
        $this->post("$root/movement/post", ['preview_token' => $fresh])->assertRedirect();
        $this->assertDatabaseCount('stock_ledger', 2);
        $this->get("$root/ledger?product_id=$id")->assertOk()->assertSee('1.250')->assertSee('12.50');
        $this->get("$root/low-stock")->assertOk()->assertSee('Reorder at 2.000');
        $second = Branch::forceCreate(['tenant_id' => $branch->tenant_id, 'name' => 'Other counter', 'code' => 'B02']);
        $pair['device']->branches()->attach($second->id, ['tenant_id' => $branch->tenant_id]);
        $this->withSession(['workspace.branch' => $second->id]);
        $this->get("$root/ledger?product_id=$id")->assertOk()->assertSee('Other counter')->assertSee('No records for this branch.');
        $this->post("$root/movement/post", ['preview_token' => $fresh])->assertForbidden();
        $upload('11.00', 0)->assertRedirect()->assertSessionHasErrors('file');
        $this->withSession(['workspace.branch' => $branch->id]);
        $cashier = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        $cashier->assignRole('Cashier');
        $this->actingAs($cashier)->get("$root/products")->assertOk()->assertDontSee('Adjust or count');
        $this->get($movement)->assertForbidden();
        $this->post("$root/movement/post", ['preview_token' => $fresh])->assertForbidden();
    }
}
