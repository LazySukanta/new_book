<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileStock extends Command
{
    protected $signature = 'stock:reconcile';

    protected $description = 'Check that stock projections match the signed ledger totals';

    public function handle(): int
    {
        $totals = DB::table('stock_ledger')->select('tenant_id', 'branch_id', 'product_id')
            ->selectRaw('SUM(qty_change) AS qty, SUM(value_change) AS value_total, MAX(id) AS last_id')
            ->groupBy('tenant_id', 'branch_id', 'product_id');
        $mismatches = DB::table('stock_balances as balance')->leftJoinSub(clone $totals, 'ledger', function ($join) {
            $join->on('ledger.tenant_id', 'balance.tenant_id')->on('ledger.branch_id', 'balance.branch_id')
                ->on('ledger.product_id', 'balance.product_id');
        })->whereRaw('balance.qty <> COALESCE(ledger.qty, 0) OR balance.inventory_value <> COALESCE(ledger.value_total, 0)
            OR NOT (balance.last_ledger_id <=> ledger.last_id)')
            ->select('balance.tenant_id', 'balance.branch_id', 'balance.product_id');
        $missing = DB::query()->fromSub($totals, 'ledger')->leftJoin('stock_balances as balance', function ($join) {
            $join->on('ledger.tenant_id', 'balance.tenant_id')->on('ledger.branch_id', 'balance.branch_id')
                ->on('ledger.product_id', 'balance.product_id');
        })->whereNull('balance.id')->select('ledger.tenant_id', 'ledger.branch_id', 'ledger.product_id');
        $errors = 0;
        foreach ($mismatches->unionAll($missing)->cursor() as $row) {
            $this->error("Stock mismatch for tenant {$row->tenant_id}, branch {$row->branch_id}, product {$row->product_id}.");
            $errors++;
        }

        if ($errors > 0) {
            return self::FAILURE;
        }

        $this->info('Stock balances match the ledger.');

        return self::SUCCESS;
    }
}
