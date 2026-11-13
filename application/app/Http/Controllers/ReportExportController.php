<?php

namespace App\Http\Controllers;

use Closure;
use App\Services\OperationalReports;
use App\Services\ReportWorkbook;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Cache\BatchCache;
use Maatwebsite\Excel\Cache\MemoryCache;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Settings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReportExportController
{
    public function download(Request $request, string $report, Excel $excel): BinaryFileResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);
        abort_unless(in_array($report, ReportController::TABLES, true), 404);
        return $this->export($request, $report, $excel, function (int $page) use ($request, $report): array {
            $copy = $request->duplicate(array_replace($request->query(), ['page' => $page, 'per_page' => 100]));
            $result = app(ReportController::class)->show($copy, app(OperationalReports::class), $report)->getData(true);
            if (! array_is_list($result['data'])) {
                $result['data'] = [$result['data']];
            }
            return $result;
        });
    }

    public function audit(Request $request, Excel $excel): BinaryFileResponse
    {
        return $this->export($request, 'audit-events', $excel, function (int $page) use ($request): array {
            $copy = $request->duplicate(array_replace($request->query(), ['page' => $page, 'per_page' => 100]));
            return app(AuditController::class)->index($copy)->getData(true);
        });
    }

    private function export(Request $request, string $report, Excel $excel, Closure $load): BinaryFileResponse
    {
        return DB::transaction(function () use ($request, $report, $excel, $load) {
            $first = $load(1);
            $first['meta']['filters'] = $request->only(['from', 'to', 'as_of', 'recorded_as_of', 'document_type', 'action', 'entity_type', 'entity_id', 'user_id']);
            if (($first['meta']['total'] ?? count($first['data'])) > ReportWorkbook::MAX_ROWS) {
                throw ValidationException::withMessages(['export' => 'Export limit is 50,000 rows. Narrow the report filters.']);
            }
            $branch = $request->attributes->get('branch');
            $workbook = new ReportWorkbook($load, $first, $report, $branch->code);
            $files = new Filesystem;
            $cachePath = storage_path('framework/cache/export-'.Str::uuid());
            $files->makeDirectory($cachePath, 0700, true);
            $previousCache = Settings::getCache();
            $previousBinder = Cell::getValueBinder();
            try {
                // Each export owns its cache so cleanup cannot evict another export's cells.
                $cellBudget = max(1, min(10000, (int) config('excel.cache.batch.memory_limit', 10000)));
                Settings::setCache(new BatchCache(new Repository(new FileStore($files, $cachePath)), new MemoryCache($cellBudget)));
                return $excel->download($workbook, $report.'-'.$branch->code.'-'.now('Asia/Kolkata')->format('Ymd-His').'.xlsx', Excel::XLSX, ['Cache-Control' => 'private, no-store']);
            } finally {
                Settings::setCache($previousCache);
                Cell::setValueBinder($previousBinder);
                $files->deleteDirectory($cachePath);
            }
        });
    }
}
