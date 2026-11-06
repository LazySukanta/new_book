<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PurchaseFixture
{
    public static function create(User $actor, int $branch, ?int $product = null): int
    {
        $supplier = DB::table('suppliers')->insertGetId(['tenant_id' => $actor->tenant_id, 'name' => 'Fixture supplier']);
        $purchase = DB::table('purchases')->insertGetId([
            'tenant_id' => $actor->tenant_id, 'branch_id' => $branch, 'supplier_id' => $supplier, 'created_by' => $actor->id,
            'supplier_invoice_no' => 'FIXTURE', 'normalized_invoice_no' => 'FIXTURE', 'supplier_invoice_date' => now()->toDateString(),
            'received_at' => now(), 'cost_includes_tax' => false, 'tax_type' => 'intra', 'supplier_snapshot' => '{}', 'branch_snapshot' => '{}',
        ]);
        if ($product === null) {
            return $purchase;
        }

        return DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchase, 'product_id' => $product, 'qty' => '1.000', 'unit_cost' => '0.00',
            'price_includes_tax' => false, 'rate_bp' => 0, 'inventory_unit_cost' => '0.000000000', 'product_snapshot' => '{}',
        ]);
    }
}
