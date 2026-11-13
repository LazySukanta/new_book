<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\DocumentNumberService;
use App\Services\HistoricalReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HistoricalReportController
{
    public const REPORTS = ['documents-issued', 'gst-sales', 'gst-purchases', 'customer-as-of', 'supplier-as-of', 'stock-as-of'];

    public function show(Request $request, HistoricalReports $reports, string $report): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $filters = $request->validate([
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d',
            'as_of' => 'sometimes|required|date', 'recorded_as_of' => 'sometimes|required|date',
            'document_type' => ['sometimes', 'required', Rule::in(array_keys(DocumentNumberService::SOURCES))],
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100',
        ]);
        abort_if($report === 'gst-purchases' && (isset($filters['as_of']) || isset($filters['recorded_as_of']))
            && ! $request->user()->hasRole('Owner'), 403, 'Historical purchase versions require owner access.');
        $effective = $this->cutoff($filters['as_of'] ?? null);
        $recorded = $this->cutoff($filters['recorded_as_of'] ?? null);
        $cutoff = $effective->lessThan($recorded) ? $effective : $recorded;
        $from = $filters['from'] ?? $filters['to'] ?? CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $to = $filters['to'] ?? $from;
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        $start = CarbonImmutable::parse($from, 'Asia/Kolkata')->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Kolkata')->addDay()->utc();
        $branch = $request->attributes->get('branch');
        return DB::transaction(function () use ($request, $reports, $report, $branch, $start, $end, $cutoff, $effective, $recorded, $from, $to, $filters) {
            $query = match ($report) {
                'documents-issued' => $reports->documents($branch, $start, $end, $cutoff, $filters['document_type'] ?? null),
                'gst-sales' => $reports->gstSales($branch, $start, $end, $cutoff),
                'gst-purchases' => $reports->gstPurchases($branch, $from, $to, $effective, $recorded),
                'customer-as-of' => $reports->parties($branch, true, $effective, $recorded),
                'supplier-as-of' => $reports->parties($branch, false, $effective, $recorded),
                'stock-as-of' => $reports->stock($branch, $cutoff),
            };
            $page = $query->paginate($filters['per_page'] ?? 25, ['*'], 'page', (int) ($filters['page'] ?? 1));
            if ($report === 'documents-issued') {
                $gaps = $reports->documentGaps(array_column($page->items(), 'series_id'), $start, $end, $cutoff);
                foreach ($page->items() as $row) {
                    $row->gap_exceptions = $gaps[$row->series_id] ?? [];
                }
            }
            return ApiResponse::data($page->items(), meta: ['branch_id' => $branch->id, 'from' => $from, 'to' => $to,
                'as_of' => $effective->toIso8601String(), 'recorded_as_of' => $recorded->toIso8601String(),
                'timezone' => 'Asia/Kolkata', 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'basis' => $report === 'stock-as-of' ? 'ledger_posting_time' : 'effective_and_recorded_time',
                'gst_basis' => 'Stored document components; internal transfers and supplier returns are not outward sales. This output does not file GST returns.']);
        });
    }

    private function cutoff(?string $value): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        if ($value === null) {
            return $now;
        }
        $date = CarbonImmutable::parse($value, 'Asia/Kolkata');
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            $date = $date->endOfDay();
        }
        $date = $date->utc();
        return $date->greaterThan($now) ? $now : $date;
    }
}
