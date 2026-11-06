<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class PairDevice extends Command
{
    protected $signature = 'shop:pair-device';

    protected $description = 'Owner-approved recovery pairing for a browser';

    public function handle(DeviceEnrollment $enrollment): int
    {
        $email = $this->ask('Owner email');
        $password = $this->secret('Owner password');
        $owner = User::where('email', $email)->first();
        if (! $owner || ! Hash::check((string) $password, $owner->password) || ! $owner->is_active
            || ! $owner->hasRole('Owner') || ! Tenant::whereKey($owner->tenant_id)->where('is_active', true)->exists()) {
            $this->error('Owner authentication failed.');

            return self::FAILURE;
        }
        $name = $this->ask('Device name');
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            $this->error('Enter a device name of 1 to 255 characters.');

            return self::FAILURE;
        }
        $result = $enrollment->register($owner, $name, $owner->accessibleBranches()->pluck('id')->all());
        $this->info('Approved for all active branches. Pair within 30 minutes:');
        $this->line($result['pairing_code']);

        return self::SUCCESS;
    }
}
