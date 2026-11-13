<?php

namespace App\Services;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class HistoricalReports
{
    public function purchases(Branch $branch, CarbonImmutable $effective, CarbonImmutable $recorded): Builder
    {
        $latest = DB::table('purchase_revisions')->where('tenant_id', $branch->tenant_id)
            ->where('financial_effective_at', '<=', $effective)->where('correction_posted_at', '<=', $recorded)
            ->select('purchase_id')->selectRaw('MAX(revision_no) AS version')->groupBy('purchase_id');
        return DB::table('purchase_revisions as revision')->joinSub($latest, 'latest', fn ($join) => $join
            ->on('latest.purchase_id', '=', 'revision.purchase_id')->on('latest.version', '=', 'revision.revision_no'))
            ->join('purchases as purchase', 'purchase.id', '=', 'revision.purchase_id')
            ->where(['purchase.tenant_id' => $branch->tenant_id, 'purchase.branch_id' => $branch->id])
            ->where('purchase.received_at', '<=', $effective)->where('revision.action', '!=', 'soft_delete');
    }

    public function documents(Branch $branch, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $cutoff, ?string $type): Builder
    {
        $records = DB::table('issued_documents as issued')->join('document_series as series', 'series.id', '=', 'issued.document_series_id')
            ->leftJoin('sales as sale', fn ($join) => $join->on('sale.id', '=', 'issued.source_id')->where('issued.source_type', 'sale'))
            ->leftJoin('sale_returns as note', fn ($join) => $join->on('note.id', '=', 'issued.source_id')->where('issued.source_type', 'sale_return'))
            ->leftJoin('stock_transfers as transfer', fn ($join) => $join->on('transfer.id', '=', 'issued.source_id')->where('issued.source_type', 'stock_transfer'))
            ->leftJoin('purchase_returns as returned', fn ($join) => $join->on('returned.id', '=', 'issued.source_id')->where('issued.source_type', 'purchase_return'))
            ->leftJoin('purchases as purchase', 'purchase.id', '=', 'returned.purchase_id')
            ->where(['series.tenant_id' => $branch->tenant_id, 'series.branch_id' => $branch->id])
            ->where('issued.issued_at', '>=', $start)->where('issued.issued_at', '<', $end)->where('issued.issued_at', '<=', $cutoff)
            ->when($type, fn ($query) => $query->where('series.document_type', $type))
            ->select('series.id as series_id', 'series.financial_year', 'series.document_type', 'series.owning_channel', 'series.prefix')
            ->selectRaw("MIN(issued.document_no) AS first_number, MAX(issued.document_no) AS last_number,
                MAX(COALESCE(JSON_VALUE(sale.invoice_snapshot, '$.branch.gstin'), JSON_VALUE(note.invoice_snapshot, '$.branch.gstin'),
                    JSON_VALUE(transfer.dispatch_snapshot, '$.source.gstin'), JSON_VALUE(purchase.branch_snapshot, '$.gstin'))) AS registration_gstin")
            ->selectRaw('MIN(issued.serial) AS first_serial, MAX(issued.serial) AS last_serial, COUNT(*) AS total,
                SUM(issued.cancelled_at IS NOT NULL AND issued.cancelled_at <= ?) AS cancelled,
                COUNT(*) - SUM(issued.cancelled_at IS NOT NULL AND issued.cancelled_at <= ?) AS net,
                MAX(issued.serial) - MIN(issued.serial) + 1 - COUNT(*) AS gaps', [$cutoff, $cutoff])
            ->groupBy('series.id', 'series.financial_year', 'series.document_type', 'series.owning_channel', 'series.prefix');
        return DB::query()->fromSub($records, 'issued_series')->orderBy('financial_year')->orderBy('document_type')->orderBy('prefix');
    }

    public function gstSales(Branch $branch, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $cutoff): Builder
    {
        $queries = [];
        foreach ([false, true] as $return) {
            $query = DB::table('sales as sale')->where(['sale.tenant_id' => $branch->tenant_id, 'sale.branch_id' => $branch->id]);
            if ($return) {
                $query->join('sale_returns as document', 'document.sale_id', '=', 'sale.id');
            }
            $source = $return ? 'document' : 'sale';
            $query->join('issued_documents as registry', 'registry.id', '=', $source.'.issued_document_id')
                ->where($source.'.issued_at', '>=', $start)->where($source.'.issued_at', '<', $end)->where($source.'.issued_at', '<=', $cutoff);
            $sign = $return ? -1 : 1;
            $snapshot = $source.'.invoice_snapshot';
            $query->select($source.'.id', 'registry.document_no', $source.'.issued_at', 'sale.document_type as original_document_type', $source.'.invoice_snapshot as snapshot')
                ->selectRaw(($return ? "'credit_note'" : 'sale.document_type')." AS document_type, $sign AS sign,
                    JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.customer.name')) AS party_name,
                    JSON_VALUE($snapshot, '$.customer.gstin') AS party_gstin,
                    JSON_VALUE($snapshot, '$.branch.gstin') AS branch_gstin,
                    COALESCE(JSON_VALUE($snapshot, '$.gst_registration_type'), JSON_VALUE($snapshot, '$.shop.gst_registration_type')) AS registration_type,
                    JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.place_of_supply_code')) AS place_of_supply,
                    CASE WHEN registry.cancelled_at IS NOT NULL AND registry.cancelled_at <= ? THEN 'cancelled' ELSE 'issued' END AS status_at_cutoff", [$cutoff]);
            foreach (['round_off', 'total'] as $field) {
                $query->selectRaw("COALESCE(JSON_VALUE($snapshot, '$.totals.$field' RETURNING DECIMAL(18,2)), 0.00) AS $field");
            }
            $queries[] = $query;
        }
        $query = DB::query()->fromSub($queries[0]->unionAll($queries[1]), 'gst_documents')
            ->crossJoin(DB::raw("JSON_TABLE(gst_documents.snapshot, '$.items[*]' COLUMNS (
                line_no FOR ORDINALITY, hsn VARCHAR(32) PATH '$.product.hsn' NULL ON EMPTY,
                rate_bp INT PATH '$.rate_bp', taxable DECIMAL(18,2) PATH '$.taxable', cgst DECIMAL(18,2) PATH '$.cgst',
                sgst DECIMAL(18,2) PATH '$.sgst', igst DECIMAL(18,2) PATH '$.igst', total DECIMAL(18,2) PATH '$.line_total'
            )) AS line"))
            ->select('gst_documents.id', 'document_no', 'issued_at', 'document_type', 'original_document_type', 'sign', 'party_name', 'party_gstin',
                'branch_gstin', 'registration_type', 'place_of_supply', 'status_at_cutoff', 'line.line_no', 'line.hsn', 'line.rate_bp',
                'gst_documents.total as document_total', 'gst_documents.round_off as document_round_off')
            ->selectRaw("CASE WHEN party_gstin IS NOT NULL AND party_gstin NOT IN ('', 'null') THEN 'B2B' ELSE 'B2C' END AS section,
                original_document_type = 'tax_invoice' AS gst_tax_invoice");
        foreach (['taxable', 'cgst', 'sgst', 'igst', 'total'] as $field) {
            $query->selectRaw("sign * line.$field AS $field, CASE WHEN status_at_cutoff = 'cancelled' THEN 0.00 ELSE sign * line.$field END AS net_$field");
        }
        return $query->orderBy('issued_at')->orderBy('document_no')->orderBy('line.line_no');
    }

    public function documentGaps(array $seriesIds, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $cutoff): array
    {
        $serials = DB::table('issued_documents')->whereIn('document_series_id', $seriesIds)
            ->where('issued_at', '>=', $start)->where('issued_at', '<', $end)->where('issued_at', '<=', $cutoff)
            ->select('document_series_id', 'serial')->selectRaw('LAG(serial) OVER (PARTITION BY document_series_id ORDER BY serial) AS previous_serial');
        return DB::query()->fromSub($serials, 'serials')->whereRaw('serial > previous_serial + 1')
            ->select('document_series_id')->selectRaw('previous_serial + 1 AS first_missing_serial, serial - 1 AS last_missing_serial')
            ->orderBy('document_series_id')->orderBy('serial')->get()->groupBy('document_series_id')->all();
    }

    public function gstPurchases(Branch $branch, string $from, string $to, CarbonImmutable $effective, CarbonImmutable $recorded): Builder
    {
        $query = $this->purchases($branch, $effective, $recorded);
        $snapshot = 'revision.after_snapshot';
        $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.supplier_invoice_date')) BETWEEN ? AND ?", [$from, $to])
            ->select('purchase.id', 'revision.revision_no', 'revision.correction_posted_at', 'revision.financial_effective_at')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.supplier_invoice_no')) AS supplier_invoice_no,
                JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.supplier_invoice_date')) AS supplier_invoice_date,
                JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.supplier_snapshot.name')) AS party_name,
                JSON_VALUE($snapshot, '$.supplier_snapshot.gstin') AS party_gstin,
                JSON_VALUE($snapshot, '$.branch_snapshot.gstin') AS branch_gstin,
                JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.tax_type')) AS tax_type,
                JSON_EXTRACT($snapshot, '$.input_tax_recoverable') AS input_tax_recoverable");
        foreach (['taxable', 'cgst', 'sgst', 'igst', 'round_off', 'total'] as $field) {
            $query->selectRaw("COALESCE(JSON_VALUE($snapshot, '$.$field' RETURNING DECIMAL(18,2)), 0.00) AS $field");
        }
        return $query->orderBy('purchase.id');
    }

    public function stock(Branch $branch, CarbonImmutable $cutoff): Builder
    {
        return DB::table('stock_ledger as ledger')->join('products as product', 'product.id', '=', 'ledger.product_id')
            ->where(['ledger.tenant_id' => $branch->tenant_id, 'ledger.branch_id' => $branch->id])->where('ledger.posted_at', '<=', $cutoff)
            ->select('ledger.product_id', 'product.name', 'product.sku')
            ->selectRaw('SUM(ledger.qty_change) AS qty, SUM(ledger.value_change) AS value, SUM(ledger.valuation_variance) AS valuation_variance')
            ->groupBy('ledger.product_id', 'product.name', 'product.sku')->orderBy('ledger.product_id');
    }

    public function parties(Branch $branch, bool $customer, CarbonImmutable $effective, CarbonImmutable $recorded): Builder
    {
        $party = $customer ? 'customer_id' : 'supplier_id';
        $opening = DB::table('party_opening_entries as entry')->join('party_accounts as account', 'account.id', '=', 'entry.party_account_id')
            ->where(['account.tenant_id' => $branch->tenant_id, 'account.branch_id' => $branch->id])->whereNotNull('account.'.$party)
            ->select('account.'.$party.' as party_id', 'entry.effective_at', 'entry.created_at', 'entry.signed_amount as amount');
        $payments = DB::table('payments')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'balance_effect' => 'normal'])->whereNotNull($party)
            ->select($party.' as party_id', 'paid_at as effective_at', 'created_at')
            ->selectRaw($customer ? "CASE WHEN direction = 'in' THEN -amount ELSE amount END AS amount" : "CASE WHEN direction = 'out' THEN -amount ELSE amount END AS amount");
        $events = $opening->unionAll($payments);
        if ($customer) {
            $sales = DB::table('sales')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id])->whereNotNull('issued_document_id')
                ->select('customer_id as party_id', 'issued_at as effective_at', 'issued_at as created_at', 'total as amount');
            $returns = DB::table('sale_returns as note')->join('sales as sale', 'sale.id', '=', 'note.sale_id')
                ->where(['sale.tenant_id' => $branch->tenant_id, 'sale.branch_id' => $branch->id])
                ->select('sale.customer_id as party_id', 'note.issued_at as effective_at', 'note.issued_at as created_at')->selectRaw('-note.total AS amount');
            $events->unionAll($sales)->unionAll($returns);
            foreach ([false, true] as $return) {
                $query = DB::table('sales as sale')->where(['sale.tenant_id' => $branch->tenant_id, 'sale.branch_id' => $branch->id]);
                if ($return) {
                    $query->join('sale_returns as note', 'note.sale_id', '=', 'sale.id');
                }
                $source = $return ? 'note' : 'sale';
                $events->unionAll($query->join('issued_documents as registry', 'registry.id', '=', $source.'.issued_document_id')->whereNotNull('registry.cancelled_at')
                    ->select('sale.customer_id as party_id', 'registry.cancelled_at as effective_at', 'registry.cancelled_at as created_at')
                    ->selectRaw(($return ? '' : '-').$source.'.total AS amount'));
            }
        } else {
            $events->unionAll($this->purchases($branch, $effective, $recorded)
                ->select('revision.resulting_supplier_id as party_id', 'purchase.received_at as effective_at', 'revision.correction_posted_at as created_at')
                ->selectRaw("JSON_VALUE(revision.after_snapshot, '$.total' RETURNING DECIMAL(18,2)) AS amount"));
            $events->unionAll(DB::table('purchase_returns as returned')->join('purchases as purchase', 'purchase.id', '=', 'returned.purchase_id')
                ->where(['purchase.tenant_id' => $branch->tenant_id, 'purchase.branch_id' => $branch->id])
                ->select('purchase.supplier_id as party_id', 'returned.issued_at as effective_at', 'returned.issued_at as created_at')->selectRaw('-returned.total AS amount'));
        }
        $balances = DB::query()->fromSub($events, 'events')->where('effective_at', '<=', $effective)->where('created_at', '<=', $recorded)
            ->select('party_id')->selectRaw('SUM(amount) AS balance')->groupBy('party_id');
        $statuses = DB::table('audit_logs')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'action' => 'opening.status', 'entity_type' => 'party_account'])
            ->where('created_at', '<=', $recorded)->whereRaw("LEFT(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(after_json, '$.opening_status_effective_at')), 'T', ' '), 19) <= ?", [$effective])
            ->select('entity_id')->selectRaw('MAX(id) AS audit_id')->groupBy('entity_id');
        return DB::table($customer ? 'customers as party' : 'suppliers as party')->where('party.tenant_id', $branch->tenant_id)
            ->when($customer, fn ($query) => $query->where('party.is_system', false))
            ->leftJoinSub($balances, 'balances', 'balances.party_id', '=', 'party.id')
            ->leftJoin('party_accounts as account', fn ($join) => $join->on('account.'.$party, '=', 'party.id')->where('account.branch_id', $branch->id)->where('account.tenant_id', $branch->tenant_id))
            ->leftJoinSub($statuses, 'status', 'status.entity_id', '=', 'account.id')->leftJoin('audit_logs as audit', 'audit.id', '=', 'status.audit_id')
            ->select('party.id as party_id', 'party.name')->selectRaw("COALESCE(balances.balance, 0.00) AS balance,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(audit.after_json, '$.opening_status')), 'not_entered') AS opening_status")
            ->orderBy('party.id');
    }
}
