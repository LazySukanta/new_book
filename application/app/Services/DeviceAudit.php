<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceAudit
{
    public static function record(string $action, User $user, Device $device): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'device_id' => $device->id,
            'action' => $action, 'entity_type' => 'device', 'entity_id' => $device->id,
            'after_json' => json_encode($device->only('status', 'channel', 'platform'), JSON_THROW_ON_ERROR),
            'request_id' => (string) Str::uuid(), 'created_at' => now(),
        ]);
    }
}
