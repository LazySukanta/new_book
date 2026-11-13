<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class DeviceAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.browser_pairing_required' => true]);
    }

    public function test_password_only_browser_login_and_restoring_pairing(): void
    {
        $this->withoutVite();
        config(['auth.browser_pairing_required' => false]);
        $owner = $this->owner();
        $credentials = ['email' => $owner->email, 'password' => 'test-password-123'];
        $this->get('/')->assertOk()->assertDontSee('Pairing code');
        $this->postJson('/auth/login', array_replace($credentials, ['password' => 'wrong']))->assertUnauthorized();
        $this->assertSame(0, Device::count());
        $this->post('/sign-in', $credentials)->assertRedirect('/workspace')->assertCookie('shop_device');
        $this->get('/workspace')->assertOk();
        $device = Device::firstOrFail();
        $this->assertTrue((bool) $device->password_only);
        $this->assertSame($owner->accessibleBranches()->pluck('id')->all(), $device->branches()->pluck('branches.id')->all());
        config(['auth.browser_pairing_required' => true]);
        $this->get('/workspace')->assertForbidden();
        $this->post('/sign-out')->assertRedirect('/');
        $this->postJson('/auth/login', $credentials)->assertUnauthorized();
        $this->get('/')->assertOk()->assertSee('Pairing code');
        $pair = $this->pairing($owner);
        $this->post('/sign-in', $this->credentials($owner, $pair['pairing_code']))->assertRedirect('/workspace');
        $this->get('/workspace')->assertOk();
    }

    public function test_password_only_login_preserves_branch_scope_revocation_and_token_pairing(): void
    {
        config(['auth.browser_pairing_required' => false]);
        $owner = $this->owner();
        $branch = Branch::firstOrFail();
        Branch::forceCreate(['tenant_id' => $owner->tenant_id, 'code' => 'B02', 'name' => 'Other branch']);
        $staff = User::factory()->create(['tenant_id' => $owner->tenant_id, 'password' => 'test-password-123']);
        $staff->assignRole('Cashier');
        $staff->branches()->attach($branch->id, ['tenant_id' => $owner->tenant_id]);
        $credentials = ['email' => $staff->email, 'password' => 'test-password-123'];
        $this->postJson('/api/v1/auth/token', $credentials)->assertUnauthorized();
        $response = $this->postJson('/auth/login', $credentials)->assertOk();
        $credential = $response->getCookie('shop_device')->getValue();
        $device = Device::firstOrFail();
        $this->assertSame([$branch->id], $device->branches()->pluck('branches.id')->all());
        $this->get('/workspace/admin/staff')->assertForbidden();
        $this->postJson('/auth/logout')->assertOk();
        $this->withCredentials()->withCookie('shop_device', $credential)->postJson('/auth/login', $credentials)->assertOk();
        $this->assertSame(1, Device::count());
        $device->update(['status' => 'revoked']);
        $this->getJson('/auth/me')->assertForbidden();
        $this->post('/sign-out')->assertRedirect('/');
        $this->withCookie('shop_device', $credential)->postJson('/auth/login', $credentials)->assertUnauthorized();
    }

    public function test_owner_registration_rejects_foreign_branches_and_derives_channel(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $this->assertSame('active', json_decode(DB::table('audit_logs')->where('action', 'device.registered')->orderByDesc('id')->value('after_json'), true, flags: JSON_THROW_ON_ERROR)['status']);
        $plain = $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$plain];
        $foreignTenant = Tenant::create(['code' => 'other', 'name' => 'Other']);
        $foreignBranch = Branch::forceCreate(['tenant_id' => $foreignTenant->id, 'code' => 'OTH', 'name' => 'Other']);
        $this->postJson('/api/v1/devices', ['name' => 'No', 'branch_ids' => [$foreignBranch->id]], $headers)->assertUnprocessable();
        $this->assertSame(1, Device::count());
        $this->postJson('/api/v1/devices', [
            'name' => 'Second', 'branch_ids' => [Branch::first()->id], 'channel' => 'windows', 'tenant_id' => $foreignTenant->id,
        ], $headers)->assertCreated()->assertJsonPath('data.device.channel', 'web')
            ->assertJsonPath('data.device.tenant_id', $owner->tenant_id);
        Tenant::whereKey($owner->tenant_id)->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertForbidden();
    }

    public function test_owner_can_recover_pairing_from_local_command(): void
    {
        $owner = $this->owner();
        $this->artisan('shop:pair-device')->expectsQuestion('Owner email', $owner->email)
            ->expectsQuestion('Owner password', 'wrong-password')->assertFailed();
        $this->assertSame(0, Device::count());
        $this->artisan('shop:pair-device')->expectsQuestion('Owner email', $owner->email)
            ->expectsQuestion('Owner password', 'test-password-123')->expectsQuestion('Device name', 'Recovery browser')
            ->assertSuccessful();
        $this->assertSame(1, Device::count());
    }

    public function test_browser_forms_enroll_staff_and_restrict_administration(): void
    {
        $this->withoutVite();
        $owner = $this->owner();
        $branch = Branch::firstOrFail();
        $pair = $this->pairing($owner);
        $this->get('/')->assertOk()->assertSee('Pairing code');
        $this->from('/')->post('/sign-in', ['email' => $owner->email, 'password' => 'bad-password'])
            ->assertRedirect('/')->assertSessionHasErrors('email');
        $this->post('/sign-in', $this->credentials($owner, $pair['pairing_code']))->assertRedirect('/workspace');
        $this->get('/workspace')->assertOk()->assertSee('Administration');
        foreach (['branches', 'staff', 'settings', 'devices', 'series'] as $section) {
            $this->get('/workspace/admin/'.$section)->assertOk();
        }
        $this->post('/workspace/admin/staff', [
            'name' => 'Shop cashier', 'email' => 'cashier@example.test', 'password' => 'test-password-123',
            'role' => 'Cashier', 'branch_ids' => [$branch->id], 'is_active' => 1,
        ])->assertRedirect('/workspace/admin/staff');
        $this->post('/workspace/admin/devices', ['name' => 'Cashier browser', 'branch_ids' => [$branch->id]])
            ->assertRedirect('/workspace/admin/devices');
        $response = $this->get('/workspace/admin/devices')->assertOk()->assertSee('One-time pairing code');
        preg_match('/<code[^>]*>([a-zA-Z0-9]{48})<\/code>/', $response->getContent(), $code);
        $this->assertNotEmpty($code[1] ?? null);
        $this->get('/workspace/admin/devices')->assertOk()->assertDontSee($code[1]);
        $series = ['branch_id' => $branch->id, 'financial_year' => '26-27', 'document_type' => 'commercial_bill', 'owning_channel' => 'web', 'prefix_list' => 'A, B'];
        $this->post('/workspace/admin/series', $series)->assertRedirect('/workspace/admin/series?branch='.$branch->id);
        $this->from('/workspace/admin/series')->post('/workspace/admin/series', $series + ['next_number' => 200])
            ->assertRedirect('/workspace/admin/series')->assertSessionHasErrors('next_number');
        $this->assertDatabaseHas('document_series', ['prefix' => 'A', 'next_number' => 1]);
        $unpaired = Branch::forceCreate(['tenant_id' => $owner->tenant_id, 'name' => 'Unpaired counter', 'code' => 'B02']);
        $this->post('/workspace/admin/series', array_replace($series, ['branch_id' => $unpaired->id]))->assertNotFound();

        $this->post('/sign-out')->assertRedirect('/');
        $this->post('/sign-in', ['email' => 'cashier@example.test', 'password' => 'test-password-123', 'pairing_code' => $code[1]])
            ->assertRedirect('/workspace');
        $this->get('/workspace')->assertOk()->assertSee($branch->name)->assertDontSee('Administration');
        $this->get('/workspace/admin/staff')->assertForbidden();
        $this->post('/workspace/admin/devices', ['name' => 'Forged', 'branch_ids' => [$branch->id]])->assertForbidden();
        Device::where('name', 'Cashier browser')->update(['status' => 'revoked']);
        $this->get('/workspace')->assertForbidden();
        $this->get('/')->assertOk()->assertSee('Sign in');
    }

    private function owner(): User
    {
        $this->seed(ShopAccessSeeder::class);
        $owner = User::factory()->create(['tenant_id' => Branch::firstOrFail()->tenant_id,
            'has_all_branches' => true, 'password' => 'test-password-123']);
        $owner->assignRole('Owner');

        return $owner;
    }

    private function pairing(User $owner): array
    {
        return app(DeviceEnrollment::class)->register($owner, 'Counter 1', $owner->accessibleBranches()->pluck('id')->all());
    }

    private function credentials(User $user, string $code): array
    {
        return ['email' => $user->email, 'password' => 'test-password-123', 'pairing_code' => $code];
    }

    public function test_pairing_is_one_time_token_is_bound_and_revocation_blocks_it(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $response = $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->assertOk();
        $token = $response->json('data.token');
        $this->assertDatabaseHas('personal_access_tokens', ['tenant_id' => $owner->tenant_id, 'device_id' => $pair['device']->id]);
        $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->assertUnauthorized();
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJsonPath('data.device.channel', 'web');
        $this->getJson('/api/v1/branch', [...$headers, 'X-Branch-Id' => (string) Branch::first()->id])->assertOk();
        $this->deleteJson('/api/v1/devices/'.$pair['device']->id, [], $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
    }

    public function test_expiry_and_tenant_binding_are_checked(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $token = $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->json('data.token');
        PersonalAccessToken::first()->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        PersonalAccessToken::first()->forceFill(['expires_at' => now()->addDay()])->save();
        $owner->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_browser_session_binding_logout_and_revoked_device(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $this->postJson('/auth/login', $this->credentials($owner, $pair['pairing_code']))->assertOk()->assertCookie('shop_device');
        $this->getJson('/auth/me')->assertOk();
        $pair['device']->update(['status' => 'revoked']);
        $this->getJson('/auth/me')->assertForbidden();
    }

    public function test_cashier_cannot_approve_devices_and_branch_grants_intersect(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $cashier = User::factory()->create(['tenant_id' => $owner->tenant_id, 'password' => 'test-password-123']);
        $cashier->assignRole('Cashier');
        $branch = Branch::firstOrFail();
        $cashier->branches()->attach($branch, ['tenant_id' => $owner->tenant_id]);
        $token = $this->postJson('/api/v1/auth/token', $this->credentials($cashier, $pair['pairing_code']))->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/v1/devices', ['name' => 'No', 'branch_ids' => [$branch->id]], $headers)->assertForbidden();
        $cashier->branches()->detach();
        $this->getJson('/api/v1/branch', [...$headers, 'X-Branch-Id' => (string) $branch->id])->assertNotFound();
    }

    public function test_login_is_throttled_and_unknown_pairing_is_rejected(): void
    {
        $owner = $this->owner();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/token', $this->credentials($owner, 'invalid'))->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/token', $this->credentials($owner, 'invalid'))->assertStatus(429);
    }

    public function test_setup_creates_one_owner_and_cannot_run_twice(): void
    {
        $this->artisan('shop:setup')->expectsQuestion('Owner name', 'Shop owner')
            ->expectsQuestion('Owner email', 'owner@example.test')
            ->expectsQuestion('Owner password (at least 12 characters)', 'test-password-123')->assertSuccessful();
        $this->assertSame(1, User::role('Owner')->count());
        $this->assertSame(1, Device::count());
        $this->artisan('shop:setup')->assertFailed();
        $this->assertSame(1, User::count());
    }

    public function test_real_csrf_check_and_database_session_binding(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->bind(PreventRequestForgery::class,
            fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $input = $this->credentials($owner, $pair['pairing_code']);
        $this->postJson('/auth/login', $input)->assertStatus(419);
        $csrf = $this->getJson('/auth/csrf')->assertOk()->json('csrf_token');
        $login = $this->postJson('/auth/login', [...$input, '_token' => $csrf])->assertOk();
        $credential = $login->getCookie('shop_device')->getValue();
        $this->assertDatabaseHas('sessions', ['tenant_id' => $owner->tenant_id, 'device_id' => $pair['device']->id, 'user_id' => $owner->id]);
        $this->getJson('/api/v1/auth/me', ['Origin' => 'http://localhost'])->assertOk();
        $csrf = $this->getJson('/auth/csrf')->json('csrf_token');
        $this->postJson('/auth/logout', ['_token' => $csrf])->assertOk();
        $this->getJson('/auth/me')->assertUnauthorized();
        $csrf = $this->getJson('/auth/csrf')->json('csrf_token');
        $this->withCredentials()->withCookie('shop_device', $credential)->postJson('/auth/login', [
            'email' => $owner->email, 'password' => 'test-password-123', '_token' => $csrf,
        ])->assertOk();
    }

    public function test_token_user_mismatch_current_roles_and_logout(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $plain = $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$plain];
        $owner->syncRoles('Cashier');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/devices', ['name' => 'No', 'branch_ids' => [Branch::first()->id]], $headers)->assertForbidden();
        $otherTenant = Tenant::create(['code' => 'foreign', 'name' => 'Foreign']);
        $foreignUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $token = PersonalAccessToken::firstOrFail();
        $token->forceFill(['tokenable_id' => $foreignUser->id])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertForbidden();
        $token->forceFill(['tokenable_id' => $owner->id])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
        $audits = DB::table('audit_logs')->get()->toJson();
        $this->assertStringNotContainsString($plain, $audits);
        $this->assertStringNotContainsString($pair['pairing_code'], $audits);
    }

    public function test_expired_pairing_and_device_branch_removal(): void
    {
        $owner = $this->owner();
        $pair = $this->pairing($owner);
        $pair['device']->update(['pairing_expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->assertUnauthorized();
        $pair['device']->update(['pairing_expires_at' => now()->addMinute()]);
        $plain = $this->postJson('/api/v1/auth/token', $this->credentials($owner, $pair['pairing_code']))->assertOk()->json('data.token');
        $pair['device']->branches()->detach();
        $this->getJson('/api/v1/branch', ['Authorization' => 'Bearer '.$plain, 'X-Branch-Id' => (string) Branch::first()->id])->assertNotFound();
    }
}
