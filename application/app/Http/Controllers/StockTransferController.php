<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Branch;
use App\Services\DocumentNumberService;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\StockProjection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StockTransferController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $filters = $request->validate(['per_page' => 'sometimes|integer|between:1,100', 'page' => 'sometimes|integer|min:1', 'status' => 'sometimes|in:draft,dispatched,received,discrepancy,resolved']);
        $branch = $request->attributes->get('branch');
        $page = DB::table('stock_transfers')->where('tenant_id', $branch->tenant_id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->where(fn ($query) => $query->where('source_branch_id', $branch->id)->orWhere('destination_branch_id', $branch->id))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 25);
        return ApiResponse::data($page->items(), meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function show(Request $request, int $transfer): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $branch = $request->attributes->get('branch');
        $record = DB::table('stock_transfers')->where(['id' => $transfer, 'tenant_id' => $branch->tenant_id])
            ->where(fn ($query) => $query->where('source_branch_id', $branch->id)->orWhere('destination_branch_id', $branch->id))->first();
        abort_unless($record, 404);
        return ApiResponse::data((array) $record + [
            'items' => DB::table('stock_transfer_items')->where('transfer_id', $transfer)->select('stock_transfer_items.*')
                ->selectRaw('qty_dispatched - qty_received - qty_recovered - qty_written_off AS unresolved_qty, dispatched_value - received_value - recovered_value - written_off_value AS in_transit_value')->orderBy('product_id')->get(),
            'resolutions' => DB::table('transfer_resolutions')->whereIn('transfer_item_id', DB::table('stock_transfer_items')->where('transfer_id', $transfer)->select('id'))->orderBy('id')->get(),
        ]);
    }

    public function save(Request $request, PostingTransaction $transaction, ?int $transfer = null): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $data = $request->validate([
            'destination_branch_id' => 'required|integer|min:1', 'note' => 'nullable|string|max:1000',
            'expected_version' => ($transfer ? 'required' : 'sometimes').'|integer|min:1',
            'items' => 'required|array|list|min:1|max:100', 'items.*' => 'array:product_id,qty',
            'items.*.product_id' => 'required|integer|min:1|distinct', 'items.*.qty' => 'required|string',
        ]);
        return $transaction->run($request, 'transfer.save', $data + ['transfer_id' => $transfer], function () use ($request, $transfer, $data) {
            $source = $request->attributes->get('branch');
            $destination = Branch::where(['tenant_id' => $source->tenant_id, 'is_active' => true])->findOrFail($data['destination_branch_id']);
            $this->lockBranches($source->tenant_id, $source->id, $destination->id);
            $this->sameRegistration($source->fresh(), $destination->fresh());
            $before = $transfer ? $this->draft($request, $transfer, $data['expected_version']) : null;
            $beforeSnapshot = $before ? (array) $before + ['items' => DB::table('stock_transfer_items')->where('transfer_id', $transfer)->get()->all()] : null;
            $items = [];
            foreach ($data['items'] as $line) {
                $product = DB::table('products')->where(['id' => $line['product_id'], 'tenant_id' => $source->tenant_id, 'is_active' => true])->whereNull('deleted_at')->first();
                abort_unless($product, 404);
                $decimal = (bool) DB::table('units')->where('id', $product->unit_id)->value('allow_decimal');
                $items[] = ['product_id' => $product->id, 'qty_requested' => Money::quantity($line['qty'], $decimal)];
            }
            $header = ['destination_branch_id' => $destination->id, 'note' => $data['note'] ?? null];
            if ($transfer) {
                DB::table('stock_transfers')->where('id', $transfer)->update($header + ['version' => $before->version + 1]);
                DB::table('stock_transfer_items')->where('transfer_id', $transfer)->delete();
            } else {
                $transfer = DB::table('stock_transfers')->insertGetId($header + ['tenant_id' => $source->tenant_id,
                    'source_branch_id' => $source->id, 'created_by' => $request->user()->id, 'created_at' => now()]);
            }
            foreach ($items as $line) {
                DB::table('stock_transfer_items')->insert($line + ['transfer_id' => $transfer]);
            }
            app(PostingAudit::class)->record($request, 'transfer.saved', 'stock_transfer', $transfer, $beforeSnapshot, $header + ['items' => $items]);
            return ApiResponse::data(['id' => $transfer, 'status' => 'draft', 'version' => $before ? $before->version + 1 : 1], $before ? 200 : 201);
        });
    }

    public function dispatch(Request $request, int $transfer, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        return $transaction->run($request, 'transfer.dispatch', $data + ['transfer_id' => $transfer], function () use ($request, $transfer, $data) {
            $actor = $request->user();
            $source = $request->attributes->get('branch');
            $initial = DB::table('stock_transfers')->where(['id' => $transfer, 'tenant_id' => $actor->tenant_id, 'source_branch_id' => $source->id])->first();
            abort_unless($initial, 404);
            $this->lockBranches($actor->tenant_id, $source->id, $initial->destination_branch_id);
            $record = $this->draft($request, $transfer, $data['expected_version']);
            $destination = Branch::findOrFail($record->destination_branch_id);
            $this->sameRegistration($source->fresh(), $destination);
            $now = now();
            $items = DB::table('stock_transfer_items')->where('transfer_id', $transfer)->orderBy('product_id')->lockForUpdate()->get();
            $values = [];
            foreach ($items as $item) {
                DB::table('products')->where('id', $item->product_id)->lockForUpdate()->first();
                $scope = ['tenant_id' => $actor->tenant_id, 'branch_id' => $source->id, 'product_id' => $item->product_id];
                $balance = app(StockProjection::class)->lock($scope);
                $qty = BigDecimal::of($item->qty_requested);
                abort_if($qty->isGreaterThan($balance->qty), 422, 'Insufficient physical stock to dispatch this transfer.');
                $value = BigDecimal::of($balance->inventory_value)->multipliedBy($qty)->dividedBy($balance->qty, 2, RoundingMode::HalfUp);
                $cost = $value->dividedBy($qty, 9, RoundingMode::HalfUp);
                DB::table('stock_transfer_items')->where('id', $item->id)->update(['qty_dispatched' => (string) $qty, 'dispatched_value' => (string) $value, 'unit_cost' => (string) $cost]);
                app(StockProjection::class)->append($scope, [
                    'type' => 'transfer_dispatch', 'qty_change' => (string) $qty->negated(), 'value_change' => (string) $value->negated(),
                    'qty_after' => (string) BigDecimal::of($balance->qty)->minus($qty), 'value_after' => (string) BigDecimal::of($balance->inventory_value)->minus($value),
                    'valuation_variance' => '0.00', 'unit_cost' => (string) $cost, 'cost_estimated' => false, 'estimated_qty' => '0.000',
                    'cost_source' => 'carrying_value', 'transfer_item_id' => $item->id, 'created_by' => $actor->id, 'posted_at' => $now,
                ], $balance->last_known_unit_cost);
                $values[] = ['product_id' => $item->product_id, 'qty' => (string) $qty, 'in_transit_value' => (string) $value];
            }
            $snapshot = ['source' => $source->fresh()->only('id', 'name', 'code', 'gstin'), 'destination' => $destination->only('id', 'name', 'code', 'gstin'), 'items' => $values];
            $issued = app(DocumentNumberService::class)->issue($actor, $source, $request->attributes->get('device'), 'stock_transfer', $now,
                function (string $number) use ($transfer, $actor, $request, $now, $snapshot) {
                    DB::table('stock_transfers')->where('id', $transfer)->update(['status' => 'dispatched', 'transfer_no' => $number,
                        'dispatched_by' => $actor->id, 'device_id' => $request->attributes->get('device')->id, 'dispatched_at' => $now,
                        'version' => DB::raw('version + 1'), 'dispatch_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                    return $transfer;
                });
            DB::table('stock_transfers')->where('id', $transfer)->update(['issued_document_id' => $issued->id]);
            $result = ['id' => $transfer, 'status' => 'dispatched', 'transfer_no' => $issued->document_no, 'items' => $values];
            app(PostingAudit::class)->record($request, 'transfer.dispatched', 'stock_transfer', $transfer, (array) $record, $result);
            return ApiResponse::data($result);
        });
    }

    public function destroy(Request $request, int $transfer, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        return $transaction->run($request, 'transfer.delete', $data + ['transfer_id' => $transfer], function () use ($request, $transfer, $data) {
            $record = $this->draft($request, $transfer, $data['expected_version']);
            $snapshot = (array) $record + ['items' => DB::table('stock_transfer_items')->where('transfer_id', $transfer)->get()->all()];
            DB::table('stock_transfer_items')->where('transfer_id', $transfer)->delete();
            DB::table('stock_transfers')->where('id', $transfer)->delete();
            app(PostingAudit::class)->record($request, 'transfer.deleted', 'stock_transfer', $transfer, $snapshot, null);
            return ApiResponse::data(['id' => $transfer, 'deleted' => true]);
        });
    }

    private function draft(Request $request, int $id, int $version): object
    {
        $record = DB::table('stock_transfers')->where(['id' => $id, 'tenant_id' => $request->user()->tenant_id,
            'source_branch_id' => $request->attributes->get('branch')->id])->lockForUpdate()->first();
        abort_unless($record, 404);
        abort_unless($record->status === 'draft' && $record->version === $version, 409, 'The transfer changed or has already been dispatched.');
        return $record;
    }

    private function lockBranches(int $tenant, int $source, int $destination): void
    {
        abort_if($source === $destination, 422, 'Select a different destination branch.');
        abort_unless(Branch::where('tenant_id', $tenant)->where('is_active', true)->whereIn('id', [$source, $destination])
            ->orderBy('id')->lockForUpdate()->get()->count() === 2, 422, 'Both branches must be active.');
    }

    private function sameRegistration(Branch $source, Branch $destination): void
    {
        abort_unless($source->gstin && $source->gstin === $destination->gstin, 422, 'Transfers require the same configured GSTIN on both branches.');
    }
}
