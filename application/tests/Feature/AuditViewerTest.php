<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditViewerTest extends PurchasePostingTest
{
    public function test_owner_audit_viewer_filters_scopes_and_redacts_legacy_payloads(): void
    {
        $this->test_purchases_post_tax_stock_revision_and_payment_atomically();
        $owner = User::role('Owner')->firstOrFail();
        $branch = Branch::firstOrFail();
        $other = Branch::forceCreate(['tenant_id' => $owner->tenant_id, 'code' => 'ALT', 'name' => 'Other branch']);
        $pair = app(DeviceEnrollment::class)->register($owner, 'Audit viewer', [$branch->id]);
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $owner->email, 'password' => 'test-password-123', 'pairing_code' => $pair['pairing_code']])->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Branch-Id' => $branch->id];
        $row = ['tenant_id' => $owner->tenant_id, 'user_id' => $owner->id, 'branch_id' => $branch->id,
            'action' => 'fixture.audit', 'entity_type' => 'product', 'entity_id' => 123,
            'after_json' => json_encode(['name' => 'Retained history', 'password' => 'never-display-credential-89', 'nested' => ['token' => 'never-display-token-89', 'amount' => '10.00']], JSON_THROW_ON_ERROR),
            'request_id' => (string) Str::uuid(), 'created_at' => now()];
        $id = DB::table('audit_logs')->insertGetId($row);
        $otherId = DB::table('audit_logs')->insertGetId(array_replace($row, ['branch_id' => $other->id, 'request_id' => (string) Str::uuid()]));
        $foreign = \App\Models\Tenant::create(['code' => 'AUDIT-OTHER', 'name' => 'Other shop']);
        $foreignUser = User::factory()->create(['tenant_id' => $foreign->id]);
        $foreignId = DB::table('audit_logs')->insertGetId(array_replace($row, ['tenant_id' => $foreign->id, 'user_id' => $foreignUser->id, 'branch_id' => null, 'request_id' => (string) Str::uuid()]));
        $this->getJson('/api/v1/audit-events?action=fixture.audit&entity_id=123', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.after_json');
        $this->getJson('/api/v1/audit-events/'.$id, $headers)->assertOk()->assertJsonPath('data.after_json.name', 'Retained history')
            ->assertJsonPath('data.after_json.nested.amount', '10.00')->assertJsonMissingPath('data.after_json.password')->assertJsonMissingPath('data.after_json.nested.token');
        $this->getJson('/api/v1/audit-events/'.$otherId, $headers)->assertNotFound();
        $this->getJson('/api/v1/audit-events/'.$foreignId, $headers)->assertNotFound();
        $export = $this->get('/api/v1/audit-events/export?action=fixture.audit', $headers)->assertOk()->assertDownload();
        $path = $export->baseResponse->getFile()->getPathname();
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(2, $book->getActiveSheet()->getHighestRow());
            $values = json_encode($book->getActiveSheet()->toArray(null, false, false), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('never-display-', $values);
            $this->assertStringNotContainsString('password', $values);
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->withoutVite();
        $this->actingAs($owner, 'web')->withSession(['device_id' => $pair['device']->id, 'tenant_id' => $owner->tenant_id, 'workspace.branch' => $branch->id]);
        $this->get('/workspace/audit?action=fixture.audit&from=&to=')->assertOk()->assertSee('Recorded audit events');
        $this->get('/workspace/audit/'.$id)->assertOk()->assertSee('Retained history')->assertSee('Before')->assertSee('After')->assertDontSee('never-display-');
        $webExport = $this->get('/workspace/audit-export?action=fixture.audit')->assertOk()->assertDownload();
        unlink($webExport->baseResponse->getFile()->getPathname());
        $owner->syncRoles(['Manager']);
        $owner->givePermissionTo('audit.view');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/audit-events', $headers)->assertForbidden();
        $this->getJson('/api/v1/audit-events/'.$id, $headers)->assertForbidden();
        $this->getJson('/api/v1/audit-events/export', $headers)->assertForbidden();
        $this->actingAs($owner, 'web');
        $this->get('/workspace/audit')->assertForbidden();
        $this->get('/workspace/audit-export')->assertForbidden();
    }
}
