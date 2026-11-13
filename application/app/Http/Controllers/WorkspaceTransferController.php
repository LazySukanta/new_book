<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Services\PostingTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class WorkspaceTransferController
{
    public function show(Request $request, ?int $transfer = null): View
    {
        $branch = $this->branch($request);
        $record = $transfer ? app(StockTransferController::class)->show($request, $transfer)->getData(true)['data'] : null;
        $page = $transfer ? [] : app(StockTransferController::class)->index($request)->getData(true);
        $items = collect($record['items'] ?? []);
        $names = DB::table('products')->where('tenant_id', $branch->tenant_id)->whereIn('id', $items->pluck('product_id'))->pluck('name', 'id');
        $rows = $items->map(fn ($item) => ['product_id' => $item['product_id'], 'name' => $names[$item['product_id']] ?? 'Product', 'qty' => $item['qty_requested']])->values()->all();
        $branchIds = $record ? [$record['source_branch_id'], $record['destination_branch_id']] : collect($page['data'] ?? [])->flatMap(fn ($row) => [$row['source_branch_id'], $row['destination_branch_id']]);
        $branchNames = Branch::where('tenant_id', $branch->tenant_id)->whereIn('id', $branchIds)->pluck('name', 'id');
        return view('transfers-workspace', [
            'branch' => $branch, 'branches' => $this->allowed($request)->orderBy('name')->get(), 'record' => $record,
            'destinations' => Branch::where(['tenant_id' => $branch->tenant_id, 'is_active' => true])->where('id', '!=', $branch->id)->orderBy('name')->get(),
            'rows' => old('items', $rows), 'names' => $names, 'branchNames' => $branchNames, 'page' => $page, 'key' => old('key', (string) Str::uuid()),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $data = $request->validate(['search' => 'required|string|max:100']);
        $query = addcslashes($data['search'], '\\%_').'%';
        return response()->json(['data' => Product::where(['tenant_id' => $branch->tenant_id, 'is_active' => true])
            ->where(fn ($builder) => $builder->where('name', 'like', $query)->orWhere('sku', 'like', $query)->orWhere('barcode', 'like', $query))
            ->orderBy('name')->limit(25)->get(['id', 'name', 'sku'])]);
    }

    public function post(Request $request, string $operation, ?int $transfer = null): RedirectResponse
    {
        $this->branch($request);
        $request->validate(['key' => 'required|uuid']);
        abort_unless($operation === 'save' || $transfer, 404);
        $request->headers->set('Idempotency-Key', $request->input('key'));
        try {
            $transaction = app(PostingTransaction::class);
            $response = match ($operation) {
                'save' => app(StockTransferController::class)->save($request, $transaction, $transfer),
                'dispatch' => app(StockTransferController::class)->dispatch($request, $transfer, $transaction),
                'delete' => app(StockTransferController::class)->destroy($request, $transfer, $transaction),
                'receive' => app(TransferReceiptController::class)->receive($request, $transfer, $transaction),
                'resolve' => app(TransferReceiptController::class)->resolve($request, $transfer, $transaction),
            };
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }
            return back()->withInput()->withErrors(['transfer' => $exception->getMessage()]);
        }
        if (! $response->isSuccessful()) {
            return back()->withInput()->withErrors(['transfer' => $response->getData(true)['message']]);
        }
        return redirect()->route('workspace.transfers', $operation === 'delete' ? [] : ['transfer' => $operation === 'save' ? $response->getData(true)['data']['id'] : $transfer])
            ->with('transfer.message', match ($operation) { 'save' => 'Draft saved.', 'dispatch' => 'Transfer dispatched.', 'delete' => 'Draft deleted.', 'receive' => 'Receipt recorded.', 'resolve' => 'Shortage resolution recorded.' });
    }

    private function allowed(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $request->user()->accessibleBranches()->whereIn('branches.id', $request->attributes->get('device')->branches()->select('branches.id'));
    }

    private function branch(Request $request): Branch
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        if ($request->filled('branch_id')) {
            $request->validate(['branch_id' => 'integer|min:1']);
            $branch = $this->allowed($request)->findOrFail($request->input('branch_id'));
            $request->session()->put('workspace.branch', $branch->id);
        } else {
            $branch = $this->allowed($request)->find(session('workspace.branch')) ?? $this->allowed($request)->orderBy('id')->firstOrFail();
        }
        $request->attributes->set('branch', $branch);
        return $branch;
    }
}
