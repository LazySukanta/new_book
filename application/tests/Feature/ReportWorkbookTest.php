<?php

namespace Tests\Feature;

use App\Services\ReportWorkbook;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportWorkbookTest extends TestCase
{
    public function test_memory_budget_rejects_an_export_before_exhaustion(): void
    {
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
        try {
            $book = new ReportWorkbook(fn () => [], ['data' => [['amount' => '1.00']], 'meta' => []], 'budget-check', 'MN1');
            $this->expectException(\Illuminate\Validation\ValidationException::class);
            iterator_to_array($book->generator());
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function test_exact_decimal_text_formula_safety_and_multi_page_generation(): void
    {
        $loaded = [];
        $first = ['data' => [['amount' => '9999999999999999.99', 'identifier' => '000001', 'name' => '=1+1']], 'meta' => ['last_page' => 2]];
        $load = function (int $page) use (&$loaded): array {
            $loaded[] = $page;
            return ['data' => [['amount' => '-10.00', 'identifier' => '000002', 'name' => '+SUM(1,2)']], 'meta' => []];
        };
        $contents = app(Excel::class)->raw(new ReportWorkbook($load, $first, 'precision-check', 'MN1'), Excel::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'report-xlsx-');
        file_put_contents($path, $contents);
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame('9999999999999999.99', $sheet->getCell('A2')->getValue());
            $this->assertSame('000001', $sheet->getCell('B2')->getValue());
            $this->assertSame('-10.00', $sheet->getCell('A3')->getValue());
            $this->assertSame('=1+1', $sheet->getCell('C2')->getValue());
            $this->assertSame('+SUM(1,2)', $sheet->getCell('C3')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C3')->getDataType());
            $this->assertSame([2], $loaded);
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
