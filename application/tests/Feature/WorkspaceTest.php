<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Tests\TestCase;

final class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_requires_a_paired_session_and_remembers_an_authorized_branch(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('Shop workspace');
        $this->get('/workspace')->assertRedirect('/');

        $this->seed(ShopAccessSeeder::class);
        $main = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $main->tenant_id, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $this->actingAs($owner)->get('/workspace')->assertForbidden();

        $second = Branch::forceCreate(['tenant_id' => $main->tenant_id, 'name' => 'Second branch', 'code' => 'B02']);
        $unpaired = Branch::forceCreate(['tenant_id' => $main->tenant_id, 'name' => 'Unpaired branch', 'code' => 'B03']);
        $other = Tenant::create(['code' => 'outside', 'name' => 'Outside tenant']);
        $foreign = Branch::forceCreate(['tenant_id' => $other->id, 'name' => 'Foreign branch', 'code' => 'F01']);
        $device = app(DeviceEnrollment::class)->register($owner, 'Test browser', [$main->id, $second->id])['device'];
        $this->withSession(['device_id' => $device->id, 'tenant_id' => $main->tenant_id]);
        $page = $this->get('/workspace')->assertOk()->assertSee('Branch at a glance')->assertDontSee($unpaired->name);
        $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');
        foreach ([$foreign->id, $unpaired->id] as $invalid) {
            $response = $this->updateWorkspace($snapshot, ['branchSelection' => (string) $invalid], 'selectBranch')->assertOk();
            $snapshot = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($main->id, $snapshot['data']['branchId']);
            $this->assertStringContainsString('Choose a branch available on this device.', $response->json('components.0.effects.html'));
        }
        $response = $this->updateWorkspace($snapshot, ['branchSelection' => (string) $second->id], 'selectBranch')->assertOk();
        $snapshot = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($second->id, $snapshot['data']['branchId']);
        $this->get('/workspace/stock')->assertOk()->assertSee($second->name);

        config(['app.debug' => false]);
        $this->updateWorkspace($snapshot, ['branchId' => $unpaired->id])->assertStatus(419);
    }

    public function test_workspace_rechecks_device_and_stock_permission_on_component_requests(): void
    {
        $this->withoutVite();
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $owner = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        $owner->assignRole('Owner');
        $cashier = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        $cashier->assignRole('Cashier');
        $device = app(DeviceEnrollment::class)->register($owner, 'Cashier browser', [$branch->id])['device'];
        $this->actingAs($cashier)->withSession(['device_id' => $device->id, 'tenant_id' => $branch->tenant_id]);

        $page = $this->get('/workspace/stock')->assertOk()->assertDontSee('>Value<', false);
        $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');
        $cashier->removeRole('Cashier');
        $this->updateWorkspace($snapshot, [])->assertForbidden();

        $cashier->assignRole('Cashier');
        $device->forceFill(['status' => 'revoked'])->save();
        $this->updateWorkspace($snapshot, [])->assertForbidden();
    }

    private function updateWorkspace(array $snapshot, array $updates, string $method = '$refresh'): TestResponse
    {
        return $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => (object) $updates,
            'calls' => [['method' => $method, 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }
}
