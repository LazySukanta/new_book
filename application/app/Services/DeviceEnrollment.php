<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceEnrollment
{
    /** @return array{device: Device, pairing_code: string} */
    public function register(User $owner, string $name, array $branchIds): array
    {
        abort_unless($owner->hasRole('Owner') && $owner->is_active, 403);
        $ids = array_values(array_unique($branchIds));
        if (! $ids || $owner->accessibleBranches()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['branch_ids' => 'Select active branches belonging to this shop.']);
        }

        return DB::transaction(function () use ($owner, $name, $ids) {
            $code = Str::random(48);
            $device = Device::create([
                'tenant_id' => $owner->tenant_id, 'device_uuid' => (string) Str::uuid(), 'name' => $name,
                'platform' => 'web', 'channel' => 'web', 'registered_by' => $owner->id, 'registered_at' => now(),
                'pairing_hash' => hash('sha256', $code), 'pairing_expires_at' => now()->addMinutes(30),
            ]);
            $device->branches()->attach(array_fill_keys($ids, ['tenant_id' => $owner->tenant_id]));
            DeviceAudit::record('device.registered', $owner, $device);

            return ['device' => $device, 'pairing_code' => $code];
        });
    }

    public function authenticate(User $user, ?string $pairingCode, ?string $credential, bool $browser): array
    {
        return DB::transaction(function () use ($user, $pairingCode, $credential, $browser) {
            $query = Device::where('tenant_id', $user->tenant_id)->where('status', 'active');
            if ($pairingCode) {
                $query->where('pairing_hash', hash('sha256', $pairingCode))->where('pairing_expires_at', '>', now())->whereNull('paired_at');
            } elseif ($browser && $credential) {
                $query->where('credential_hash', hash('sha256', $credential));
            } else {
                abort(401, 'Device pairing is required.');
            }
            $device = $query->lockForUpdate()->first();
            abort_unless($device, 401, 'Device authorization failed.');
            abort_unless($user->accessibleBranches()->whereIn('id', $device->branches()->select('branches.id'))->exists(), 403);
            $newCredential = null;
            if ($pairingCode) {
                $newCredential = $browser ? Str::random(64) : null;
                $device->forceFill(['paired_at' => now(), 'pairing_hash' => null, 'pairing_expires_at' => null,
                    'credential_hash' => $newCredential ? hash('sha256', $newCredential) : null])->save();
            }
            $device->forceFill(['last_seen_at' => now()])->save();
            DeviceAudit::record($pairingCode ? 'device.paired' : 'device.authenticated', $user, $device);

            return [$device, $newCredential];
        });
    }
}
