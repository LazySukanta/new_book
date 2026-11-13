<?php

namespace App\Services;

use Closure;
use Generator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ReportWorkbook extends StringValueBinder implements FromGenerator, WithHeadings, WithCustomValueBinder, WithProperties, WithStyles, WithTitle, WithColumnWidths
{
    public const MAX_ROWS = 50000;

    private array $columns;

    public function __construct(private readonly Closure $loadPage, private readonly array $firstPage, private readonly string $report, private readonly string $branch)
    {
        $this->columns = array_keys($firstPage['data'][0] ?? []);
        $this->setSetIgnoredErrors(true);
    }

    public function generator(): Generator
    {
        $count = 0;
        $setting = trim((string) ini_get('memory_limit'));
        $memoryLimit = (int) $setting * match (strtolower(substr($setting, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        $lastPage = (int) ($this->firstPage['meta']['last_page'] ?? 1);
        for ($page = 1; $page <= $lastPage; $page++) {
            $result = $page === 1 ? $this->firstPage : ($this->loadPage)($page);
            foreach ($result['data'] as $row) {
                if ($memoryLimit > 0 && memory_get_usage(true) + 16 * 1024 * 1024 >= $memoryLimit) {
                    throw ValidationException::withMessages(['export' => 'This export is too large for the available memory. Narrow the report filters.']);
                }
                if (++$count > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['export' => 'Export limit is 50,000 rows. Narrow the report filters.']);
                }
                yield array_map(function (string $column) use ($row): string {
                    $value = $row[$column] ?? '';
                    if (is_array($value)) {
                        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }
                    if (is_bool($value)) {
                        return $value ? 'True' : 'False';
                    }
                    return (string) $value;
                }, $this->columns);
            }
        }
    }

    public function headings(): array
    {
        return $this->columns ? array_map(fn ($field) => ucwords(str_replace('_', ' ', $field)), $this->columns) : ['No matching records'];
    }

    public function title(): string
    {
        return mb_substr(str_replace('-', ' ', $this->report), 0, 31);
    }

    public function properties(): array
    {
        return ['creator' => 'KhataPOS', 'title' => $this->title(), 'subject' => 'Branch '.$this->branch,
            'description' => 'Amounts and identifiers are stored as exact text to preserve decimal precision and prevent spreadsheet formulas. Report context: '
                .json_encode($this->firstPage['meta'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->columns)));
        $sheet->setAutoFilter('A1:'.$lastColumn.$sheet->getHighestRow());
        $sheet->getRowDimension(1)->setRowHeight(30);
        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '172554']], 'alignment' => ['wrapText' => true]]];
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->headings() as $index => $heading) {
            $widths[Coordinate::stringFromColumnIndex($index + 1)] = min(36, max(18, mb_strlen($heading) + 4));
        }
        return $widths;
    }
}
