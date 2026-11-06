<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class TradingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || DB::scalar('SELECT DATABASE()') !== 'khatapos_test') {
            throw new \LogicException('Trading demo data requires the dedicated MySQL test database.');
        }

        $this->call(ShopAccessSeeder::class);
        DB::transaction(function () {
            $branch = Branch::where('code', 'MN1')->firstOrFail();
            $branch->forceFill(['state_code' => '36'])->save();
            $tenant = ['tenant_id' => $branch->tenant_id];
            DB::table('shop_settings')->insertOrIgnore($tenant + ['name' => 'Trading day demo', 'gst_registration_type' => 'unregistered']);
            foreach (['Owner', 'Manager', 'Cashier'] as $role) {
                $staff = User::firstOrCreate(['email' => strtolower($role).'@trading.example.test'], $tenant + [
                    'name' => 'Trading '.$role, 'password' => bin2hex(random_bytes(24)), 'has_all_branches' => true,
                ]);
                $staff->assignRole($role);
            }
            Customer::firstOrCreate($tenant + ['name' => 'Trading customer'], ['credit_limit' => '100.00']);
            Supplier::firstOrCreate($tenant + ['name' => 'Trading supplier']);
            $unit = DB::table('units')->where($tenant + ['code' => 'M36'])->value('id')
                ?? DB::table('units')->insertGetId($tenant + ['code' => 'M36', 'name' => 'Piece']);
            $rate = DB::table('tax_rates')->where($tenant + ['name' => 'M36 Zero'])->value('id')
                ?? DB::table('tax_rates')->insertGetId($tenant + ['name' => 'M36 Zero', 'rate_bp' => 0]);
            Product::firstOrCreate($tenant + ['sku' => 'M36-PEN'], [
                'barcode' => '360101', 'name' => 'Trading pen', 'unit_id' => $unit, 'tax_rate_id' => $rate,
                'retail_price' => '10.00', 'wholesale_price' => '8.00', 'purchase_price' => '4.00', 'tax_inclusive' => false,
            ]);
            $owner = User::where('email', 'owner@trading.example.test')->firstOrFail();
            foreach (['commercial_bill' => 'A', 'credit_note' => 'X'] as $type => $prefix) {
                DB::table('document_series')->insertOrIgnore($tenant + [
                    'branch_id' => $branch->id, 'financial_year' => ShopCalendar::financialYear(now()), 'document_type' => $type,
                    'owning_channel' => 'web', 'prefix' => $prefix, 'rollover_order' => 1, 'created_by' => $owner->id,
                ]);
            }
        });
    }
}
