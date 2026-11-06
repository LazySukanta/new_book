<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Middleware\ResolveBranch;
use App\Models\Product;
use App\Services\CatalogueWriter;
use App\Services\Money;
use App\Services\PostingTransaction;
use App\Services\StockAdjustment;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogueImportController
{
    private const COLUMNS = [
        'name', 'sku', 'barcode', 'category', 'unit', 'gst_rate', 'hsn',
        'retail_price', 'wholesale_price', 'purchase_price', 'reorder_level', 'opening_qty', 'opening_unit_cost',
    ];

    private const REQUIRED = ['name', 'unit', 'gst_rate', 'retail_price', 'wholesale_price'];

    public function __invoke(Request $request, CatalogueWriter $writer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('products.manage') && $user->can('prices.manage'), 403);
        $input = $request->validate([
            'file' => 'required|file|max:2048', 'dry_run' => 'sometimes|boolean',
            'posted_at' => 'prohibited', 'date' => 'prohibited', 'effective_at' => 'prohibited',
        ]);
        $rows = $this->read($input['file']->getRealPath());
        $dryRun = $request->boolean('dry_run');
        $opening = collect($rows)->contains(fn ($row) => ($row['opening_qty'] ?? '') !== '' || ($row['opening_unit_cost'] ?? '') !== '');
        $run = function () use ($request, $writer, $user, $rows, $dryRun, $opening) {
            $branch = $request->attributes->get('branch');
            if (! $dryRun) {
                DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->first();
                if ($opening) {
                    $user->accessibleBranches()->lockForUpdate()->findOrFail($branch->id);
                }
            }
            [$prepared, $errors] = $this->prepare($writer, $user->tenant_id, $rows, $branch?->id);
            if ($errors !== []) {
                return response()->json(['message' => 'Correct the CSV rows and try again.', 'errors' => $errors], 422);
            }
            if (! $dryRun) {
                // Reserve explicit SKUs before allocating blank SKUs.
                $openingItems = [];
                foreach ([true, false] as $explicit) {
                    foreach ($prepared as $row) {
                        if (isset($row[1]['sku']) === $explicit) {
                            $product = $writer->persist($request, $row);
                            if ($row[4] !== null) {
                                $openingItems[] = array_replace($row[4], ['product_id' => $product->id]);
                            }
                        }
                    }
                }
                if ($openingItems !== []) {
                    app(StockAdjustment::class)->post($request, 'opening', 'CSV opening stock', null, $openingItems);
                }
            }

            return ApiResponse::data(['dry_run' => $dryRun, 'rows' => count($prepared), 'written' => $dryRun ? 0 : count($prepared)]);
        };

        if (! $opening) {
            return $dryRun ? $run() : DB::transaction($run, 3);
        }
        abort_unless($user->can('stock.adjust'), 403);

        return app(ResolveBranch::class)->handle($request, fn (Request $request) => $dryRun ? $run()
            : app(PostingTransaction::class)->run($request, 'products.import_with_opening', $rows, $run));
    }

    private function read(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'Could not read the CSV.']);
        }
        try {
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $columns = fgetcsv($stream, escape: '');
            if ($columns === false || $columns === [null]) {
                throw ValidationException::withMessages(['file' => 'The CSV needs a header.']);
            }
            $columns = array_map('trim', $columns);
            if (in_array('opening_qty', $columns, true) !== in_array('opening_unit_cost', $columns, true)) {
                throw ValidationException::withMessages(['file' => 'Opening stock needs both opening_qty and opening_unit_cost columns.']);
            }
            if (count($columns) !== count(array_unique($columns)) || array_diff($columns, self::COLUMNS)
                || array_diff(self::REQUIRED, $columns)) {
                throw ValidationException::withMessages(['file' => 'The CSV header contains missing, duplicate or unknown columns.']);
            }
            $rows = [];
            for ($number = 2; ($values = fgetcsv($stream, escape: '')) !== false; $number++) {
                if ($values === [null]) {
                    continue;
                }
                if (count($rows) >= 2000) {
                    throw ValidationException::withMessages(['file' => 'Import at most 2,000 data rows at a time.']);
                }
                $rows[$number] = count($values) === count($columns) && mb_check_encoding($values, 'UTF-8')
                    ? array_combine($columns, array_map('trim', $values))
                    : null;
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The CSV needs at least one data row.']);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function prepare(CatalogueWriter $writer, int $tenant, array $rows, ?int $branch): array
    {
        $prepared = [];
        $errors = [];
        $seen = [];
        $collations = collect(DB::select('SHOW FULL COLUMNS FROM products'))->pluck('Collation', 'Field');
        $inclusive = (bool) (DB::table('shop_settings')->where('tenant_id', $tenant)->value('price_inclusive_default') ?? true);
        foreach ($rows as $number => $row) {
            if ($row === null) {
                $errors["rows.$number.file"] = ['Use valid UTF-8 and the same number of columns as the header.'];

                continue;
            }
            try {
                foreach (['sku', 'barcode'] as $field) {
                    if (($row[$field] ?? '') === '') {
                        continue;
                    }
                    $collation = $collations[$field];
                    $key = DB::scalar("SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE $collation))", [$row[$field]]);
                    if (isset($seen[$field][$key])) {
                        throw ValidationException::withMessages([$field => 'Already used on row '.$seen[$field][$key].'.']);
                    }
                    $seen[$field][$key] = $number;
                }

                $id = ($row['sku'] ?? '') === '' ? null : Product::where('tenant_id', $tenant)->where('sku', $row['sku'])->value('id');
                $data = $this->productData($tenant, $row, $inclusive, $id === null);
                $prepared[] = [...$writer->prepare($tenant, $id, $data), $this->opening($row, $data['unit_id'], $id, $branch)];
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $errors["rows.$number.$field"] = $messages;
                }
            }
        }

        return [$prepared, $errors];
    }

    private function opening(array $row, int $unit, ?int $product, ?int $branch): ?array
    {
        $qty = $row['opening_qty'] ?? '';
        $cost = $row['opening_unit_cost'] ?? '';
        if ($qty === '' && $cost === '') {
            return null;
        }
        if ($qty === '' || $cost === '') {
            throw ValidationException::withMessages(['opening_qty' => 'Supply opening quantity and explicit unit cost together.']);
        }
        if (($row['sku'] ?? '') === '') {
            throw ValidationException::withMessages(['sku' => 'Opening stock requires an explicit SKU for safe reimports.']);
        }
        $adjustment = app(StockAdjustment::class);
        if ($product !== null && $adjustment->hasOpening($branch, $product)) {
            throw ValidationException::withMessages(['opening_qty' => 'Opening stock already exists for this branch and product.']);
        }

        return $adjustment->openingItem($product ?? 0, $qty, $cost, (bool) DB::table('units')->where('id', $unit)->value('allow_decimal'));
    }

    private function productData(int $tenant, array $row, bool $inclusive, bool $new): array
    {
        $data = array_intersect_key($row, array_flip([
            'name', 'sku', 'barcode', 'hsn', 'retail_price', 'wholesale_price', 'purchase_price', 'reorder_level',
        ]));
        foreach (['sku', 'barcode', 'hsn', 'purchase_price', 'reorder_level'] as $optional) {
            if (($data[$optional] ?? '') === '') {
                unset($data[$optional]);
            }
        }
        if ($new) {
            $data['tax_inclusive'] = $inclusive;
        }
        $data['reason'] = 'Product CSV import';
        $data['unit_id'] = $this->reference($tenant, 'units', 'code', $row['unit'], 'unit');
        if (($row['category'] ?? '') !== '') {
            $data['category_id'] = $this->reference($tenant, 'categories', 'name', $row['category'], 'category');
        }
        try {
            $rate = BigDecimal::of(Money::amount($row['gst_rate']))->multipliedBy(100);
            if ($rate->isGreaterThan(10000)) {
                throw ValidationException::withMessages(['gst_rate' => 'Use a percentage from 0 to 100.']);
            }
        } catch (ValidationException) {
            throw ValidationException::withMessages(['gst_rate' => 'Use a percentage from 0 to 100 with up to two decimals.']);
        }
        $data['tax_rate_id'] = $this->reference($tenant, 'tax_rates', 'rate_bp', $rate->toInt(), 'gst_rate');

        return $data;
    }

    private function reference(int $tenant, string $table, string $column, string|int $value, string $field): int
    {
        $matches = DB::table($table)->where('tenant_id', $tenant)->where('is_active', true)
            ->where($column, $value)->limit(2)->pluck('id');
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([$field => 'Use a value matching exactly one active catalogue reference.']);
        }

        return $matches->first();
    }
}
