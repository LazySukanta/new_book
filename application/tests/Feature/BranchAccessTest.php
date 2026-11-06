<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveBranch;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_branch_membership_and_tenant_boundaries(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $this->seed(ShopAccessSeeder::class);
        $main = Branch::firstOrFail();
        $other = Tenant::create(['code' => 'other', 'name' => 'Other']);
        $foreign = Branch::forceCreate(['tenant_id' => $other->id, 'code' => 'MN1', 'name' => 'Other main']);
        $cashier = User::factory()->create(['tenant_id' => $main->tenant_id]);
        $cashier->assignRole('Cashier');
        $cashier->branches()->attach($main, ['tenant_id' => $main->tenant_id]);
        $this->assertTrue($cashier->can('sales.create'));
        $this->assertFalse($cashier->can('devices.manage'));
        Route::middleware(['web', 'auth', ResolveBranch::class])->get('/test/branch', fn (Request $r) => ['id' => $r->attributes->get('branch')->id]);
        $this->actingAs($cashier)->getJson('/test/branch', ['X-Branch-Id' => $main->id])->assertOk();
        $this->getJson('/test/branch', ['X-Branch-Id' => $foreign->id])->assertNotFound();
        $cashier->forceFill(['has_all_branches' => true])->save();
        $this->getJson('/test/branch', ['X-Branch-Id' => $foreign->id])->assertNotFound();
        $main->forceFill(['is_active' => false])->save();
        $this->getJson('/test/branch', ['X-Branch-Id' => $main->id])->assertNotFound();
    }
}
