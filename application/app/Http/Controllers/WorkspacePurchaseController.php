<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\PostingTransaction;
use App\Services\PurchaseCorrectionPosting;
use App\Services\ShopCalendar;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class WorkspacePurchaseController
{
    public function show(Request $request): View
    {
        $section = $request->route('section');
        $purchase = $request->route('purchase');
        $branch = $this->branch($request);
        $owner = $request->user()->hasRole('Owner') && $request->user()->can('purchases.revise');
        abort_if(in_array($section, ['correct', 'history'], true) && ! $owner, 403);
        $controller = app(PurchaseController::class);
        $record = $purchase === null ? null : $controller->show($request, $purchase)->getData(true)['data'];
        abort_if($section === 'correct' && $record['status'] !== 'posted', 404);
        $page = match ($section) {
            'index' => $controller->index($request)->getData(true),
            'history' => app(PurchaseCorrectionController::class)->history($request, $purchase)->getData(true),
            default => [],
        };
        $edit = in_array($section, ['create', 'correct'], true);
        $suppliers = $products = [];
        if ($edit) {
            $suppliers = Supplier::where('tenant_id', $branch->tenant_id)
                ->where(fn ($query) => $query->where('is_active', true)->when($record, fn ($query) => $query->orWhere('id', $record['supplier_id'])))
                ->orderBy('name')->pluck('name', 'id');
            $products = Product::withTrashed()->where('tenant_id', $branch->tenant_id)
                ->where(fn ($query) => $query->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))
                    ->orWhereIn('id', array_column($record['items'] ?? [], 'product_id')))
                ->orderBy('name')->get(['id', 'sku', 'name'])->mapWithKeys(fn (Product $row) => [$row->id => $row->name.' · '.$row->sku]);
        }

        return view('purchases-workspace', [
            'section' => $section, 'branch' => $branch, 'record' => $record, 'owner' => $owner,
            'canPay' => $request->user()->can('payments.pay_supplier'),
            'suppliers' => $suppliers, 'products' => $products, 'rows' => $page['data'] ?? [], 'meta' => $page['meta'] ?? [],
            'key' => old('key', (string) Str::uuid()), 'today' => ShopCalendar::localDate(now()),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $input = $request->validate(['branch_id' => 'required|integer|min:1', 'key' => 'required|uuid']);
        abort_unless((int) $input['branch_id'] === $branch->id, 403);
        $request->headers->set('Idempotency-Key', $input['key']);
        $this->cleanRows($request);

        try {
            $response = app(PurchaseController::class)->store($request, app(PostingTransaction::class));
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
        } catch (HttpExceptionInterface $exception) {
            if (!in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['purchase' => $exception->getMessage()]);
        }
        $body = $response->getData(true);
        if (!$response->isSuccessful()) {
            return back()->withInput()->withErrors(['purchase' => $body['message']]);
        }

        return redirect()->route('workspace.purchases.detail', ['purchase' => $body['data']['id']])->with('purchase.message', 'Purchase recorded.');
    }

    public function returnGoods(Request $request, int $purchase): RedirectResponse
    {
        $this->branch($request);
        $request->validate(['key' => 'required|uuid', 'items' => 'required|array']);
        $request->merge(['items' => array_values(array_filter($request->input('items'),
            fn ($line) => is_array($line) && isset($line['qty']) && $line['qty'] !== '' && $line['qty'] !== '0'))]);
        $request->headers->set('Idempotency-Key', $request->input('key'));
        try {
            $response = app(PurchaseReturnController::class)->store($request, $purchase, app(PostingTransaction::class));
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }
            return back()->withInput()->withErrors(['return' => $exception->getMessage()]);
        }
        if (! $response->isSuccessful()) {
            return back()->withInput()->withErrors(['return' => $response->getData(true)['message']]);
        }
        if ($request->routeIs('workspace.purchases.return-preview')) {
            return back()->withInput()->with('purchase.return_preview', $response->getData(true)['data']);
        }
        return redirect()->route('workspace.purchases.detail', $purchase)
            ->with('purchase.message', 'Supplier return '.$response->getData(true)['data']['document_no'].' posted.');
    }

    public function supplier(Request $request): RedirectResponse
    {
        $this->branch($request);
        $supplier = app(SupplierController::class)->store($request)->getData(true)['data'];

        return redirect()->route('workspace.purchases.create', ['supplier' => $supplier['id']])->with('purchase.message', 'Supplier added.');
    }

    public function preview(Request $request, int $purchase): RedirectResponse
    {
        $branch = $this->branch($request);
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('purchases.revise'), 403);
        $input = $request->validate(['branch_id' => 'required|integer|min:1', 'action' => 'required|in:update,soft_delete']);
        abort_unless((int) $input['branch_id'] === $branch->id, 403);
        $this->cleanRows($request);
        if ($input['action'] === 'soft_delete') {
            $request->replace($request->only(['action', 'expected_revision', 'reason', 'payments', 'received_at', 'financial_effective_at']));
        }

        try {
            $response = $this->correction($request, $purchase);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
        } catch (HttpExceptionInterface $exception) {
            if (!in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['purchase' => $exception->getMessage()]);
        }
        $body = $response->getData(true);
        if (!$response->isSuccessful()) {
            return back()->withInput()->withErrors(['purchase' => $body['message']]);
        }
        $preview = $body['data'];
        $payload = $request->only(['expected_revision', 'reason', 'action', 'supplier_id', 'supplier_invoice_no',
            'supplier_invoice_date', 'cost_includes_tax', 'input_tax_recoverable', 'tax_type', 'discount_amount', 'items']);
        $token = Crypt::encryptString(json_encode([
            'actor' => $request->user()->id, 'branch' => $branch->id, 'purchase' => $purchase,
            'expires' => now()->addMinutes(20)->timestamp, 'key' => (string) Str::uuid(),
            'payload' => $payload + ['preview_hash' => $preview['preview_hash']],
        ], JSON_THROW_ON_ERROR));

        return back()->withInput()->with(['purchase.preview' => $preview, 'purchase.token' => $token, 'purchase.preview_id' => $purchase]);
    }

    public function confirm(Request $request, int $purchase): RedirectResponse
    {
        $branch = $this->branch($request);
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('purchases.revise'), 403);
        $input = $request->validate(['preview_token' => 'required|string|max:65536']);
        try {
            $token = json_decode(Crypt::decryptString($input['preview_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException) {
            abort(403);
        }
        abort_unless($token['actor'] === $request->user()->id && $token['branch'] === $branch->id && $token['purchase'] === $purchase, 403);
        if ($token['expires'] < now()->timestamp) {
            return back()->withErrors(['purchase' => 'The preview expired. Review the correction again.']);
        }
        $request->query->replace([]);
        $request->replace($token['payload']);
        $request->headers->set('Idempotency-Key', $token['key']);
        try {
            $response = $this->correction($request, $purchase);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
        } catch (HttpExceptionInterface $exception) {
            if (!in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['purchase' => $exception->getMessage()]);
        }
        if (!$response->isSuccessful()) {
            return back()->withInput(collect($token['payload'])->except('preview_hash', 'expected_revision')->all())
                ->withErrors(['purchase' => $response->getData(true)['message']]);
        }

        return redirect()->route('workspace.purchases.detail', ['purchase' => $purchase])
            ->with('purchase.message', $token['payload']['action'] === 'soft_delete' ? 'Purchase removed from active records. Its history is retained.' : 'Correction posted.');
    }

    private function correction(Request $request, int $purchase): \Illuminate\Http\JsonResponse
    {
        return app(PurchaseCorrectionController::class)->handle($request, $purchase,
            app(PurchaseCorrectionPosting::class), app(PostingTransaction::class));
    }

    private function cleanRows(Request $request): void
    {
        if (is_array($request->input('items'))) {
            $request->merge(['items' => array_values(array_map(function ($row) {
                if (is_array($row)) {
                    foreach (['rate_bp', 'confirmed_remaining'] as $field) {
                        if (($row[$field] ?? null) === null) {
                            unset($row[$field]);
                        }
                    }
                }

                return $row;
            }, $request->input('items')))]);
        }
        if (is_array($request->input('payments'))) {
            $request->merge(['payments' => array_values($request->input('payments'))]);
        }
    }

    private function branch(Request $request): Branch
    {
        $actor = $request->user();
        abort_unless($actor->can('purchases.manage'), 403);
        $device = $request->attributes->get('device');
        $allowed = $actor->accessibleBranches()->whereIn('branches.id', $device->branches()->select('branches.id'));
        $id = session('workspace.branch');
        $branch = ($id ? (clone $allowed)->find($id) : null) ?? $allowed->orderBy('name')->orderBy('id')->firstOrFail();
        $request->attributes->set('branch', $branch);

        return $branch;
    }
}
