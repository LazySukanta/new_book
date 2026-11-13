<?php

namespace App\Services;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OperationalReports
{
    public function transfers(Branch $branch): Builder
    {
        return DB::table('stock_transfer_items as item')->join('stock_transfers as transfer', 'transfer.id', '=', 'item.transfer_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->join('branches as destination', 'destination.id', '=', 'transfer.destination_branch_id')
            ->where(['transfer.tenant_id' => $branch->tenant_id, 'transfer.source_branch_id' => $branch->id])
            ->where('transfer.status', '!=', 'draft')
            ->select('transfer.id as transfer_id', 'transfer.transfer_no', 'transfer.status', 'transfer.dispatched_at',
                'destination.name as destination', 'item.product_id', 'product.name', 'product.sku', 'item.qty_dispatched',
                'item.qty_received', 'item.qty_recovered', 'item.qty_written_off', 'item.dispatched_value', 'item.received_value', 'item.recovered_value', 'item.written_off_value')
            ->selectRaw('item.qty_dispatched - item.qty_received - item.qty_recovered - item.qty_written_off AS unresolved_qty,
                item.dispatched_value - item.received_value - item.recovered_value - item.written_off_value AS in_transit_value');
    }

    public function transferResolutions(Branch $branch, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('transfer_resolutions as resolution')->join('stock_transfer_items as item', 'item.id', '=', 'resolution.transfer_item_id')
            ->join('stock_transfers as transfer', 'transfer.id', '=', 'item.transfer_id')->join('products as product', 'product.id', '=', 'item.product_id')
            ->where(['transfer.tenant_id' => $branch->tenant_id, 'transfer.source_branch_id' => $branch->id])
            ->where('resolution.resolved_at', '>=', $start)->where('resolution.resolved_at', '<', $end)
            ->select('resolution.id', 'transfer.id as transfer_id', 'transfer.transfer_no', 'product.name', 'resolution.resolution',
                'resolution.qty', 'resolution.value', 'resolution.reason', 'resolution.resolved_at');
    }

    public function sales(Branch $branch, CarbonImmutable $start, CarbonImmutable $end, ?int $cashier): Builder
    {
        $events = [];
        foreach ([false, true] as $return) {
            foreach ([false, true] as $cancel) {
                $query = DB::table('sales as sale')->where([
                    'sale.tenant_id' => $branch->tenant_id, 'sale.branch_id' => $branch->id,
                ])->whereNotNull('sale.issued_document_id')
                    ->when($cashier, fn ($query) => $query->where('sale.cashier_id', $cashier));
                $source = $return ? 'note' : 'sale';
                if ($return) {
                    $query->join('sale_returns as note', 'note.sale_id', '=', 'sale.id')->whereNotNull('note.issued_document_id');
                }
                $date = $source.'.issued_at';
                if ($cancel) {
                    $query->join('issued_documents as registry', 'registry.id', '=', $source.'.issued_document_id')->whereNotNull('registry.cancelled_at');
                    $date = 'registry.cancelled_at';
                }
                $query->where($date, '>=', $start)->where($date, '<', $end);
                $sign = $return === $cancel ? 1 : -1;
                $bills = $return ? 0 : $sign;
                $credit = $return ? '0.00' : "COALESCE((SELECT JSON_VALUE(audit.after_json, '$.balance_amount' RETURNING DECIMAL(18,2))
                    FROM audit_logs AS audit WHERE audit.tenant_id = sale.tenant_id AND audit.entity_type = 'sale'
                    AND audit.entity_id = sale.id AND audit.action = 'sale.completed' ORDER BY audit.id DESC LIMIT 1), 0.00) * $sign";
                $isReturn = (int) $return;
                $events[] = $query->selectRaw("$date AS event_at, sale.cashier_id, $source.invoice_snapshot AS snapshot,
                    $source.id AS document_id, $isReturn AS is_return, $sign AS sign, $bills AS bills,
                    $source.total * $sign AS total, $credit AS credit");
            }
        }

        return DB::query()->fromSub($events[0]->unionAll($events[1])->unionAll($events[2])->unionAll($events[3]), 'sales_events');
    }

    public function lines(Builder $sales): Builder
    {
        $saleLines = (clone $sales)->where('is_return', 0)
            ->join('sale_items as item', 'item.sale_id', '=', 'sales_events.document_id')
            ->select('event_at', 'sign', 'item.line_snapshot')->selectRaw('item.cost_snapshot AS cost_snapshot');
        $returnLines = $sales->where('is_return', 1)
            ->join('sale_return_items as item', 'item.sale_return_id', '=', 'sales_events.document_id')
            ->select('event_at', 'sign', 'item.line_snapshot')->selectRaw("JSON_EXTRACT(item.line_snapshot, '$.cost') AS cost_snapshot");
        $stored = DB::query()->fromSub($saleLines->unionAll($returnLines), 'stored_lines')->select('event_at', 'sign')
            ->selectRaw("JSON_VALUE(line_snapshot, '$.product_id' RETURNING UNSIGNED) AS product_id,
                JSON_VALUE(line_snapshot, '$.product.name' RETURNING CHAR(255)) AS name,
                JSON_VALUE(line_snapshot, '$.qty' RETURNING DECIMAL(18,3)) AS qty,
                JSON_VALUE(line_snapshot, '$.price_includes_tax') = 'true' AS price_includes_tax,
                JSON_VALUE(cost_snapshot, '$.cost_total' RETURNING DECIMAL(18,2)) AS cost,
                JSON_VALUE(cost_snapshot, '$.estimated_qty' RETURNING DECIMAL(18,3)) AS estimated_qty,
                JSON_VALUE(cost_snapshot, '$.estimated_unit_cost' RETURNING DECIMAL(24,9)) AS estimated_unit_cost");
        foreach (['taxable' => 'taxable', 'cgst' => 'cgst', 'sgst' => 'sgst', 'igst' => 'igst', 'line_total' => 'total',
            'gross' => 'gross', 'line_discount' => 'line_discount', 'bill_discount_share' => 'bill_discount'] as $field => $alias) {
            $stored->selectRaw("JSON_VALUE(line_snapshot, '$.$field' RETURNING DECIMAL(18,2)) AS $alias");
        }

        return DB::query()->fromSub($stored, 'line_items');
    }

    public function products(Builder $sales): Builder
    {
        return $this->lines($sales)->select('product_id')->selectRaw('MAX(name) AS name, SUM(sign * qty) AS qty,
            SUM(sign * taxable) AS taxable, SUM(sign * (cgst + sgst + igst)) AS tax, SUM(sign * total) AS total,
            SUM(sign * cost) AS cost, SUM(sign * (taxable - cost)) AS gross_profit,
            SUM(sign * ROUND(COALESCE(estimated_unit_cost * estimated_qty, 0), 2)) AS estimated_cogs')
            ->groupBy('product_id');
    }

    public function payments(Branch $branch): Builder
    {
        return DB::table('payments')->where(['payments.tenant_id' => $branch->tenant_id, 'payments.branch_id' => $branch->id]);
    }

    public function stock(Branch $branch): Builder
    {
        return DB::table('products')->leftJoin('stock_balances as balance', fn ($join) => $join
            ->on('balance.product_id', '=', 'products.id')->where('balance.tenant_id', $branch->tenant_id)->where('balance.branch_id', $branch->id))
            ->where('products.tenant_id', $branch->tenant_id)
            ->where(fn ($query) => $query->whereNull('products.deleted_at')->orWhere('balance.qty', '<>', 0))
            ->select('products.id as product_id', 'products.name', 'products.sku', 'products.reorder_level')
            ->selectRaw('COALESCE(balance.qty, 0.000) AS qty, COALESCE(balance.inventory_value, 0.00) AS value,
                COALESCE(balance.avg_cost, 0.000000000) AS avg_cost, COALESCE(balance.valuation_provisional, 0) AS valuation_provisional');
    }

    public function lowStock(Branch $branch): Builder
    {
        return $this->stock($branch)->whereNull('products.deleted_at')->where('products.is_active', true)
            ->whereRaw('COALESCE(balance.qty, 0) <= products.reorder_level');
    }

    public function outstanding(Branch $branch, bool $customer): Builder
    {
        $party = $customer ? 'customer_id' : 'supplier_id';
        $table = $customer ? 'customers' : 'suppliers';
        $scope = ['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id];
        $opening = DB::table('party_opening_entries as entry')->join('party_accounts as account', 'account.id', '=', 'entry.party_account_id')
            ->where(['account.tenant_id' => $branch->tenant_id, 'account.branch_id' => $branch->id])
            ->whereNotNull('account.'.$party)->select('account.'.$party.' as party_id', 'entry.signed_amount as amount');
        $payments = $this->payments($branch)->whereNotNull($party)->where('balance_effect', 'normal')->select($party.' as party_id')
            ->selectRaw($customer ? "CASE WHEN direction = 'in' THEN -amount ELSE amount END AS amount"
                : "CASE WHEN direction = 'out' THEN -amount ELSE amount END AS amount");
        $documents = DB::table($customer ? 'sales' : 'purchases')->where($scope + ['status' => $customer ? 'completed' : 'posted'])
            ->select($party.' as party_id', 'total as amount');
        $events = $opening->unionAll($payments)->unionAll($documents);
        if ($customer) {
            $events->unionAll(DB::table('sale_returns as note')->join('sales as sale', 'sale.id', '=', 'note.sale_id')
                ->where(['sale.tenant_id' => $branch->tenant_id, 'sale.branch_id' => $branch->id, 'note.status' => 'issued'])
                ->select('sale.customer_id as party_id')->selectRaw('-note.total AS amount'));
        }
        if (! $customer) {
            $events->unionAll(DB::table('purchase_returns as returned')->join('purchases as purchase', 'purchase.id', '=', 'returned.purchase_id')
                ->where(['purchase.tenant_id' => $branch->tenant_id, 'purchase.branch_id' => $branch->id])
                ->select('purchase.supplier_id as party_id')->selectRaw('-returned.total AS amount'));
        }
        $balances = DB::query()->fromSub($events, 'party_events')->select('party_id')->selectRaw('SUM(amount) AS balance')->groupBy('party_id');
        $lastPayment = $this->payments($branch)->whereNotNull($party)->where('balance_effect', 'normal')->whereNull('reversal_of_id')
            ->where('status', 'posted')->where('direction', $customer ? 'in' : 'out')
            ->select($party.' as party_id')->selectRaw('MAX(paid_at) AS last_payment')->groupBy($party);

        return DB::table($table.' as party')->where('party.tenant_id', $branch->tenant_id)
            ->when($customer, fn ($query) => $query->where('party.is_system', false))
            ->leftJoinSub($balances, 'balances', 'balances.party_id', '=', 'party.id')
            ->leftJoinSub($lastPayment, 'last_payment', 'last_payment.party_id', '=', 'party.id')
            ->leftJoin('party_accounts as account', fn ($join) => $join->on('account.'.$party, '=', 'party.id')
                ->where('account.tenant_id', $branch->tenant_id)->where('account.branch_id', $branch->id))
            ->select('party.id as '.$party, 'party.name', 'party.phone', 'last_payment.last_payment')
            ->selectRaw("COALESCE(balances.balance, 0.00) AS balance,
                COALESCE(account.opening_status, 'not_entered') AS opening_status,
                COALESCE(account.opening_status, 'not_entered') = 'not_entered' AS opening_balance_incomplete");
    }
}
