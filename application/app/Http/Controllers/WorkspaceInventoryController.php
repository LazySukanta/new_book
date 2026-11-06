<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CatalogueWriter;
use App\Services\OperationalReports;
use App\Services\StockAdjustment;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkspaceInventoryController
{
    public function show(Request $request, string $section, ?int $product = null): View
    {
        $actor = $request->user();
        $canEdit = $actor->can('products.manage') && $actor->can('prices.manage');
        abort_unless(match ($section) {
            'products' => $actor->can('products.view'),
            'edit', 'import', 'references' => $canEdit,
            'ledger', 'low-stock' => $actor->can('stock.view'),
            'movement' => $actor->can('stock.adjust'),
        }, 403);
        $branch = $this->branch($request);
        $catalogue = app(CatalogueController::class);
        $item = $product === null ? null : Product::where('tenant_id', $actor->tenant_id)->findOrFail($product);
        $query = match ($section) {
            'products' => $catalogue->index($request)->getData(true),
            'edit' => $item ? $catalogue->history($request, $item->id)->getData(true) : [],
            'ledger' => app(StockController::class)->ledger($request)->getData(true),
            'low-stock' => $this->lowStock($request, $branch),
            default => [],
        };
        if ($section === 'ledger') {
            $names = Product::withTrashed()->where('tenant_id', $actor->tenant_id)
                ->whereIn('id', array_column($query['data'], 'product_id'))->pluck('name', 'id');
            foreach ($query['data'] as &$row) {
                $row['name'] = $names[$row['product_id']] ?? null;
            }
            unset($row);
        }
        $references = [];
        if ($section === 'edit') {
            foreach (['units' => 'unit_id', 'tax_rates' => 'tax_rate_id', 'categories' => 'category_id'] as $table => $field) {
                $references[$table] = DB::table($table)->where('tenant_id', $actor->tenant_id)
                    ->where(fn ($q) => $q->where('is_active', true)->when($item, fn ($q) => $q->orWhere('id', $item->$field ?? 0)))
                    ->orderBy('name')->pluck('name', 'id');
            }
        }

        if ($section === 'references') {
            foreach (['units', 'tax-rates', 'categories'] as $reference) {
                $references[$reference] = app(CatalogueReferenceController::class)->index($request, $reference)->getData(true)['data'];
            }
        }

        return view('inventory', [
            'section' => $section, 'branch' => $branch, 'item' => $item, 'records' => $query['data'] ?? [],
            'pagination' => $query['meta'] ?? [], 'references' => $references, 'canEdit' => $canEdit,
            'canAdjust' => $actor->can('stock.adjust'), 'canSeeValue' => $actor->can('reports.view'),
            'key' => (string) Str::uuid(),
            'slabs' => $section === 'edit' && $item ? DB::table('product_price_slabs')->where('product_id', $item->id)
                ->orderBy('min_qty')->get(['min_qty', 'price'])->map(fn ($row) => (array) $row)->all() : [],
        ]);
    }

    public function saveProduct(Request $request, ?int $product = null): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('products.manage') && $actor->can('prices.manage'), 403);
        if ($request->boolean('manage_slabs')) {
            $data = $request->validate(['slabs' => 'present|array|max:5', 'slabs.*' => 'array:min_qty,price',
                'slabs.*.min_qty' => 'nullable|string', 'slabs.*.price' => 'nullable|string']);
            $request->merge(['slabs' => array_values(array_filter($data['slabs'],
                fn (array $row) => isset($row['min_qty']) || isset($row['price'])))]);
        }
        if ($product !== null) {
            $current = Product::where('tenant_id', $actor->tenant_id)->findOrFail($product);
            foreach (['unit_id', 'tax_rate_id', 'category_id'] as $field) {
                $value = $request->input($field);
                if ((is_scalar($value) || $value === null) && (string) $value === (string) $current->$field) {
                    $request->request->remove($field);
                }
            }
        }
        $catalogue = app(CatalogueController::class);
        $response = $product === null ? $catalogue->store($request) : $catalogue->update($request, $product);

        return redirect()->route('workspace.inventory', ['section' => 'edit', 'product' => $response->getData(true)['data']['id']])
            ->with('inventory.message', 'Product saved.');
    }

    public function saveReference(Request $request, string $reference): RedirectResponse
    {
        app(CatalogueReferenceController::class)->store($request, $reference);

        return redirect()->route('workspace.inventory', ['section' => 'references'])->with('inventory.message', 'Catalogue option saved.');
    }

    public function import(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $input = $request->validate(['key' => 'required|uuid', 'branch_id' => 'required|integer|min:1']);
        if ((int) $input['branch_id'] !== $branch->id) {
            return back()->withErrors(['file' => 'The active branch changed. Review the branch and select the CSV again.']);
        }
        $request->headers->set('Idempotency-Key', $input['key']);
        try {
            $response = app(CatalogueImportController::class)($request, app(CatalogueWriter::class));
        } catch (HttpResponseException $error) {
            $response = $error->getResponse();
        }
        $body = $response->getData(true);
        if (! $response->isSuccessful()) {
            return back()->withErrors($body['errors'] ?? ['file' => $body['message']]);
        }
        $data = $body['data'];

        return back()->with('inventory.message', $data['dry_run']
            ? "{$data['rows']} rows passed validation. Select the same CSV to import it."
            : "{$data['written']} rows imported.");
    }

    public function preview(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $data = $request->validate([
            'kind' => 'required|in:adjustment,count_correction', 'product_id' => 'required|integer|min:1',
            'date' => 'prohibited', 'posted_at' => 'prohibited', 'effective_at' => 'prohibited',
            'quantity' => 'required|string', 'unit_cost' => 'nullable|string', 'reason' => 'required|string|max:255',
        ]);
        $kind = $data['kind'];
        $item = ['product_id' => (int) $data['product_id'], 'unit_cost' => $data['unit_cost'] ?? null,
            ($kind === 'count_correction' ? 'counted_qty' : 'qty_change') => $data['quantity']];
        $preview = app(StockAdjustment::class)->preview($request, $kind, $item);
        $item['expected_last_ledger_id'] = $preview['last_ledger_id'];
        $token = Crypt::encryptString(json_encode([
            'actor' => $request->user()->id, 'branch' => $branch->id, 'expires' => now()->addMinutes(20)->timestamp,
            'kind' => $kind, 'reason' => $data['reason'], 'item' => $item, 'key' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));

        return back()->withInput()->with('inventory.preview', $preview)->with('inventory.token', $token);
    }

    public function postMovement(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $input = $request->validate(['preview_token' => 'required|string|max:4096', 'date' => 'prohibited', 'posted_at' => 'prohibited', 'effective_at' => 'prohibited']);
        try {
            $intent = json_decode(Crypt::decryptString($input['preview_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException) {
            abort(403);
        }
        abort_unless($intent['actor'] === $request->user()->id && $intent['branch'] === $branch->id, 403);
        if ($intent['expires'] < now()->timestamp) {
            return back()->withErrors(['preview' => 'Preview expired. Review the current balance again.']);
        }
        $request->replace(['reason' => $intent['reason'], 'items' => [$intent['item']]]);
        $request->headers->set('Idempotency-Key', $intent['key']);
        $controller = app(StockAdjustmentController::class);
        try {
            $response = $intent['kind'] === 'count_correction' ? $controller->count($request) : $controller->adjust($request);
        } catch (HttpResponseException $error) {
            $response = $error->getResponse();
        }
        if (! $response->isSuccessful()) {
            return back()->withErrors(['preview' => $response->getData(true)['message']])
                ->withInput([
                    'kind' => $intent['kind'], 'product_id' => $intent['item']['product_id'], 'reason' => $intent['reason'],
                    'quantity' => $intent['item']['counted_qty'] ?? $intent['item']['qty_change'], 'unit_cost' => $intent['item']['unit_cost'],
                ]);
        }

        return redirect()->route('workspace.inventory', ['section' => 'ledger', 'product_id' => $intent['item']['product_id']])
            ->with('inventory.message', 'Stock movement posted.');
    }

    private function branch(Request $request): \App\Models\Branch
    {
        $device = $request->attributes->get('device');
        $query = $request->user()->accessibleBranches()->whereIn('branches.id', $device->branches()->select('branches.id'));
        $id = session('workspace.branch');
        $branch = ($id ? (clone $query)->find($id) : null) ?? $query->orderBy('name')->orderBy('id')->firstOrFail();
        $request->attributes->set('branch', $branch);
        $request->headers->set('X-Branch-Id', (string) $branch->id);

        return $branch;
    }

    private function lowStock(Request $request, \App\Models\Branch $branch): array
    {
        $page = app(OperationalReports::class)->lowStock($branch)->orderBy('products.name')->orderBy('products.id')->paginate(25);
        $rows = $page->items();
        if (! $request->user()->can('reports.view')) {
            foreach ($rows as $row) {
                unset($row->value, $row->avg_cost);
            }
        }

        return ['data' => array_map(fn ($row) => (array) $row, $rows),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]];
    }
}
