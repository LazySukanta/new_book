<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\OperationalReports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportController
{
    public const TABLES = [
        'daily-sales', 'sales-by-product', 'sales-by-cashier', 'stock-valuation', 'low-stock',
        'customer-outstanding', 'supplier-outstanding', 'payments', 'profit',
        'transfers', 'in-transit', 'transfer-discrepancies', 'transfer-resolutions',
        ...HistoricalReportController::REPORTS,
    ];

    public function show(Request $request, OperationalReports $reports, string $report): JsonResponse
    {
        if (in_array($report, HistoricalReportController::REPORTS, true)) {
            return app(HistoricalReportController::class)->show($request, app(\App\Services\HistoricalReports::class), $report);
        }
        $actor = $request->user();
        $own = ! $actor->can('reports.view');
        abort_if($own && ($report !== 'daily-sales' || ! $actor->can('reports.daily_own')), 403);
        $branch = $request->attributes->get('branch');
        $filters = $request->validate([
            'from' => 'sometimes|required|date_format:Y-m-d',
            'to' => 'sometimes|required|date_format:Y-m-d',
            'page' => 'sometimes|required|integer|min:1',
            'per_page' => 'sometimes|required|integer|between:1,100',
        ]);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $from = $filters['from'] ?? $filters['to'] ?? $today;
        $to = $filters['to'] ?? $from;
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        $start = CarbonImmutable::parse($from, 'Asia/Kolkata')->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Kolkata')->addDay()->utc();
        $sales = $reports->sales($branch, $start, $end, $own ? $actor->id : null);
        $payments = $this->period($reports->payments($branch), 'paid_at', $start, $end)
            ->when($own, fn ($query) => $query->where('created_by', $actor->id)->whereNotNull('customer_id'));

        return DB::transaction(function () use ($report, $reports, $branch, $sales, $payments, $filters, $from, $to, $start, $end, $actor, $request) {
            $stock = fn () => $reports->stock($branch);
            $customers = fn () => $reports->outstanding($branch, true);
            $suppliers = fn () => $reports->outstanding($branch, false);
            $profit = function () use ($reports, $sales, $branch, $start, $end) {
                $totals = DB::query()->fromSub($reports->products(clone $sales), 'products')
                    ->selectRaw('COALESCE(SUM(taxable), 0.00) AS taxable, COALESCE(SUM(cost), 0.00) AS cost,
                        COALESCE(SUM(gross_profit), 0.00) AS gross_profit, COALESCE(SUM(estimated_cogs), 0.00) AS estimated_cogs')->first();
                $movements = DB::table('stock_ledger')->where(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id]);

                return (array) $totals + [
                    'valuation_variance' => $this->period($movements, 'posted_at', $start, $end)
                        ->selectRaw('COALESCE(SUM(valuation_variance), 0.00) AS variance')->value('variance'),
                    'provisional' => true,
                ];
            };
            $meta = ['branch_id' => $branch->id, 'from' => $from, 'to' => $to, 'timezone' => 'Asia/Kolkata'];
            if ($report === 'profit') {
                return ApiResponse::data($profit(), meta: $meta);
            }
            if ($report === 'dashboard') {
                $key = implode(':', ['dashboard-v2', $branch->tenant_id, $branch->id, $actor->id,
                    $request->attributes->get('device')->id, $from, $to]);
                $dashboard = Cache::remember($key, 60, fn () => [
                    'sales' => (clone $sales)->selectRaw('COALESCE(SUM(total), 0.00) AS total, COALESCE(SUM(bills), 0) AS bills')->first(),
                    'collections' => $this->collections(clone $payments)->get(),
                    'stock_value' => DB::query()->fromSub($stock(), 'stock')->selectRaw('COALESCE(SUM(value), 0.00) AS amount')->value('amount'),
                    'low_stock_count' => $reports->lowStock($branch)->count(),
                    'customer_dues' => $this->dues($customers()),
                    'supplier_dues' => $this->dues($suppliers()),
                    'top_products' => $reports->products(clone $sales)->orderByDesc('total')->orderBy('product_id')->limit(5)->get(),
                    'profit' => $profit(),
                    'in_transit_value' => DB::query()->fromSub($reports->transfers($branch), 'transfers')->selectRaw('COALESCE(SUM(in_transit_value), 0.00) AS amount')->value('amount'),
                    'transfer_discrepancies' => $reports->transfers($branch)->where('transfer.status', 'discrepancy')->distinct()->count('transfer.id'),
                ]);

                return ApiResponse::data($dashboard, meta: $meta + ['cache_seconds' => 60]);
            }
            $query = match ($report) {
                'transfers' => $reports->transfers($branch)->orderByDesc('transfer.id')->orderBy('item.id'),
                'in-transit' => $reports->transfers($branch)->whereRaw('item.qty_dispatched > item.qty_received + item.qty_recovered + item.qty_written_off')->orderByDesc('transfer.id')->orderBy('item.id'),
                'transfer-discrepancies' => $reports->transfers($branch)->where('transfer.status', 'discrepancy')
                    ->whereRaw('item.qty_dispatched > item.qty_received + item.qty_recovered + item.qty_written_off')->orderByDesc('transfer.id')->orderBy('item.id'),
                'transfer-resolutions' => $reports->transferResolutions($branch, $start, $end)->orderByDesc('resolution.resolved_at')->orderByDesc('resolution.id'),
                'daily-sales' => $this->daily($reports, $sales, $payments),
                'sales-by-product' => $reports->products($sales)->orderByDesc('total')->orderBy('product_id'),
                'sales-by-cashier' => $sales->leftJoin('users', 'users.id', '=', 'sales_events.cashier_id')
                    ->select('cashier_id', 'users.name')->selectRaw('SUM(bills) AS bills, SUM(total) AS total')
                    ->groupBy('cashier_id', 'users.name')->orderBy('cashier_id'),
                'stock-valuation' => $stock()->orderBy('products.name')->orderBy('products.id'),
                'low-stock' => $reports->lowStock($branch)->orderBy('qty')->orderBy('products.id'),
                'customer-outstanding' => $customers()->orderByDesc('balance')->orderBy('party.id'),
                'supplier-outstanding' => $suppliers()->orderByDesc('balance')->orderBy('party.id'),
                'payments' => $payments->leftJoin('customers', 'customers.id', '=', 'payments.customer_id')
                    ->leftJoin('suppliers', 'suppliers.id', '=', 'payments.supplier_id')
                    ->select('payments.id', 'paid_at', 'customer_id', 'supplier_id', 'direction', 'mode', 'amount',
                        'payments.status', 'purpose', 'balance_effect', 'reversal_of_id')
                    ->selectRaw('COALESCE(customers.name, suppliers.name) AS party_name')->orderByDesc('paid_at')->orderByDesc('payments.id'),
            };
            if (in_array($report, ['stock-valuation', 'low-stock', 'customer-outstanding', 'supplier-outstanding', 'transfers', 'in-transit', 'transfer-discrepancies'], true)) {
                $meta = ['branch_id' => $branch->id, 'basis' => 'current'];
            }
            if (in_array($report, ['transfers', 'in-transit', 'transfer-discrepancies', 'transfer-resolutions'], true)) {
                $meta['attribution'] = 'source_branch';
            }
            $page = $query->paginate($filters['per_page'] ?? 25, ['*'], 'page', (int) ($filters['page'] ?? 1));
            $items = $page->items();
            if ($report === 'daily-sales') {
                $collections = $this->collections($payments)
                    ->selectRaw("DATE(DATE_ADD(paid_at, INTERVAL 330 MINUTE)) AS date")->groupBy('date')->whereIn(
                        DB::raw('DATE(DATE_ADD(paid_at, INTERVAL 330 MINUTE))'), array_column($items, 'date')
                    )->get()->groupBy('date');
                foreach ($items as $row) {
                    $row->collected_by_mode = ($collections[$row->date] ?? collect())->pluck('amount', 'mode');
                }
            }

            return ApiResponse::data($items, meta: $meta + [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ]);
        });
    }

    private function period(Builder $query, string $column, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $query->where($column, '>=', $start)->where($column, '<', $end);
    }

    private function daily(OperationalReports $reports, Builder $sales, Builder $payments): Builder
    {
        $dates = (clone $sales)->selectRaw('DATE(DATE_ADD(event_at, INTERVAL 330 MINUTE)) AS date')
            ->union((clone $payments)->whereNotNull('customer_id')->where('balance_effect', 'normal')
                ->selectRaw('DATE(DATE_ADD(paid_at, INTERVAL 330 MINUTE)) AS date'));
        $totals = (clone $sales)->selectRaw("DATE(DATE_ADD(event_at, INTERVAL 330 MINUTE)) AS date, SUM(bills) AS bills,
            SUM(total) AS net, SUM(credit) AS credit,
            SUM(sign * JSON_VALUE(snapshot, '$.totals.round_off' RETURNING DECIMAL(18,2))) AS round_off")->groupBy('date');
        $lines = $reports->lines($sales)->selectRaw('DATE(DATE_ADD(event_at, INTERVAL 330 MINUTE)) AS date,
            SUM(sign * COALESCE(gross, IF(price_includes_tax, total, taxable) + line_discount + bill_discount)) AS gross,
            SUM(sign * (line_discount + bill_discount)) AS discount, SUM(sign * taxable) AS taxable,
            SUM(sign * (cgst + sgst + igst)) AS tax')->groupBy('date');

        return DB::query()->fromSub($dates, 'days')->leftJoinSub($totals, 'totals', 'totals.date', '=', 'days.date')
            ->leftJoinSub($lines, 'line_totals', 'line_totals.date', '=', 'days.date')
            ->select('days.date')->selectRaw('COALESCE(totals.bills, 0) AS bills, COALESCE(totals.net, 0.00) AS net,
                COALESCE(totals.credit, 0.00) AS credit, COALESCE(line_totals.gross, 0.00) AS gross,
                COALESCE(line_totals.discount, 0.00) AS discount, COALESCE(line_totals.taxable, 0.00) AS taxable,
                COALESCE(line_totals.tax, 0.00) AS tax, COALESCE(totals.round_off, 0.00) AS round_off')->orderByDesc('days.date');
    }

    private function collections(Builder $payments): Builder
    {
        return $payments->whereNotNull('customer_id')->where('balance_effect', 'normal')->select('mode')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS amount")->groupBy('mode');
    }

    private function dues(Builder $parties): object
    {
        return DB::query()->fromSub($parties, 'parties')
            ->selectRaw('COALESCE(SUM(GREATEST(balance, 0)), 0.00) AS outstanding,
                COALESCE(SUM(GREATEST(-balance, 0)), 0.00) AS credit,
                COALESCE(SUM(opening_balance_incomplete), 0) AS incomplete_opening_accounts')->first();
    }
}
