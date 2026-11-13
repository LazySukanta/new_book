<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceTransferTest extends StockTransferTest
{
    public function test_browser_forms_create_dispatch_receive_and_resolve_transfer(): void
    {
        $this->test_transfer_drafts_dispatch_stock_once_and_preserve_in_transit_value();
        $this->withoutVite();
        $owner = User::role('Owner')->firstOrFail();
        $previous = DB::table('stock_transfers')->where('status', 'dispatched')->first();
        $item = DB::table('stock_transfer_items')->where('transfer_id', $previous->id)->first();
        $pair = app(DeviceEnrollment::class)->register($owner, 'Browser forms', [$previous->source_branch_id, $previous->destination_branch_id]);
        $this->actingAs($owner, 'web')->withSession(['device_id' => $pair['device']->id, 'tenant_id' => $owner->tenant_id, 'workspace.branch' => $previous->source_branch_id]);
        $this->get('/workspace/transfers')->assertOk()->assertSee('New transfer draft')->assertSee('Shortages');
        $this->getJson('/workspace/transfers/products?search=Fixture')->assertOk()->assertJsonPath('data.0.id', $item->product_id);
        $form = ['key' => (string) Str::uuid(), 'branch_id' => $previous->source_branch_id, 'destination_branch_id' => $previous->destination_branch_id,
            'items' => [['product_id' => $item->product_id, 'qty' => '5']], 'note' => 'Browser transfer'];
        $this->post('/workspace/transfers/actions/save', $form)->assertRedirect();
        $id = DB::table('stock_transfers')->where('note', 'Browser transfer')->value('id');
        $this->assertNotNull($id);
        $this->get('/workspace/transfers/'.$id)->assertOk()->assertSee('Confirm dispatch');
        $this->post('/workspace/transfers/actions/dispatch/'.$id, ['key' => (string) Str::uuid(), 'branch_id' => $previous->source_branch_id, 'expected_version' => 1])->assertRedirect('/workspace/transfers/'.$id);
        $this->get('/workspace/transfers/'.$id.'?branch_id='.$previous->destination_branch_id)->assertOk()->assertSee('Confirm final receipt');
        $receivedItem = DB::table('stock_transfer_items')->where('transfer_id', $id)->value('id');
        $this->post('/workspace/transfers/actions/receive/'.$id, ['key' => (string) Str::uuid(), 'branch_id' => $previous->destination_branch_id,
            'expected_version' => 2, 'items' => [['transfer_item_id' => $receivedItem, 'qty' => '4']]])->assertRedirect('/workspace/transfers/'.$id);
        $this->get('/workspace/transfers/'.$id)->assertOk()->assertSee('Resolve a shortage');
        $this->post('/workspace/transfers/actions/resolve/'.$id, ['key' => (string) Str::uuid(), 'branch_id' => $previous->destination_branch_id,
            'expected_version' => 3, 'transfer_item_id' => $receivedItem, 'resolution' => 'recovered', 'qty' => '1', 'reason' => 'Second package found'])->assertRedirect('/workspace/transfers/'.$id);
        $this->get('/workspace/transfers/'.$id)->assertOk()->assertSee('Resolved')->assertSee('Second package found')->assertDontSee('Confirm final receipt');
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $previous->destination_branch_id, 'product_id' => $item->product_id, 'qty' => '5.000', 'inventory_value' => '54.50']);
        $this->get('/workspace/transfers?status=discrepancy')->assertOk()->assertSee('No transfers in this view.');
        $owner->syncRoles(['Cashier']);
        $this->get('/workspace/transfers')->assertForbidden();
        $this->getJson('/workspace/report-data/in-transit')->assertForbidden();
    }
}
