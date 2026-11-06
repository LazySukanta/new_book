<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ShopAccessSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(['code' => 'shop'], ['name' => 'KhataPOS']);
        DB::table('customers')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'name' => 'Walk-in', 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! Branch::where('tenant_id', $tenant->id)->exists()) {
            Branch::unguarded(fn () => Branch::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'MN1'], ['name' => 'Main branch']));
        }

        $cashier = ['products.view', 'customers.view', 'customers.create', 'sales.create', 'sales.discount', 'payments.receive', 'stock.view', 'reports.daily_own'];
        $manager = [...$cashier, 'users.manage', 'products.manage', 'prices.manage', 'customers.manage', 'suppliers.manage', 'purchases.manage', 'sales.override_price', 'sales.override_credit', 'sales.override_place_of_supply', 'sales.cancel', 'sales.return', 'payments.pay_supplier', 'payments.reverse', 'stock.adjust', 'stock.transfer', 'reports.view'];
        $owner = [...$manager, 'branches.manage', 'settings.manage', 'devices.manage', 'audit.view', 'purchases.revise', 'opening.manage'];
        foreach ($owner as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (['Owner' => $owner, 'Manager' => $manager, 'Cashier' => $cashier] as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
    }
}
