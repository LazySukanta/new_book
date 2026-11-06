<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetupShop extends Command
{
    protected $signature = 'shop:setup';

    protected $description = 'Create the first owner and issue a one-time browser pairing code';

    public function handle(DeviceEnrollment $enrollment): int
    {
        app(ShopAccessSeeder::class)->run();
        $tenant = Tenant::where('code', 'shop')->firstOrFail();
        if (User::where('tenant_id', $tenant->id)->role('Owner')->exists()) {
            $this->error('An owner already exists. Use owner-approved device registration.');

            return self::FAILURE;
        }
        $input = ['name' => $this->ask('Owner name'), 'email' => $this->ask('Owner email'),
            'password' => $this->secret('Owner password (at least 12 characters)')];
        $validator = Validator::make($input, [
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', Password::min(12)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $result = DB::transaction(function () use ($tenant, $input, $enrollment) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (User::where('tenant_id', $tenant->id)->role('Owner')->exists()) {
                throw new \RuntimeException('An owner was created by another setup process.');
            }
            $owner = User::forceCreate([...$input, 'tenant_id' => $tenant->id, 'has_all_branches' => true]);
            $owner->assignRole('Owner');

            return $enrollment->register($owner, 'First browser', $owner->accessibleBranches()->pluck('id')->all());
        });
        $this->info('Owner created. Pair within 30 minutes using this one-time code:');
        $this->line($result['pairing_code']);

        return self::SUCCESS;
    }
}
