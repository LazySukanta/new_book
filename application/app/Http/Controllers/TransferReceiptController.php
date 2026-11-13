<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Branch;
use App\Services\Money;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use App\Services\StockProjection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TransferReceiptController
{
    public function receive(Request $request, int $transfer, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $data = $request->validate(['expected_version' => 'required|integer|min:1',
            'items' => 'required|array|list|min:1|max:100', 'items.*' => 'array:transfer_item_id,qty',
            'items.*.transfer_item_id' => 'required|integer|min:1|distinct', 'items.*.qty' => 'required|string']);
        return $transaction->run($request, 'transfer.receive', $data + ['transfer_id' => $transfer], function () use ($request, $transfer, $data) {
            $record = $this->lock($request, $transfer, $data['expected_version']);
            abort_unless($record->status === 'dispatched' && $record->received_at === null, 409, 'The transfer has already been received or is not dispatched.');
            $items = DB::table('stock_transfer_items')->where('transfer_id', $transfer)->orderBy('product_id')->lockForUpdate()->get();
            $inputs = collect($data['items'])->keyBy('transfer_item_id');
            abort_unless($inputs->count() === $items->count() && $items->every(fn ($item) => $inputs->has($item->id)), 422, 'Confirm a received quantity for every dispatched item, including zero for missing goods.');
            foreach ($items as $item) {
                $qty = $this->quantity($item->product_id, $inputs[$item->id]['qty'], true);
                abort_if($qty->isGreaterThan($item->qty_dispatched), 422, 'Received quantity exceeds dispatched quantity.');
                $value = $this->portion($item, $qty);
                if ($qty->isPositive()) {
                    $this->stock($request, $item, $qty, $value, ['transfer_item_id' => $item->id], 'transfer_receipt');
                }
                DB::table('stock_transfer_items')->where('id', $item->id)->update(['qty_received' => (string) $qty, 'received_value' => (string) $value]);
            }
            $status = $this->status($transfer, false);
            DB::table('stock_transfers')->where('id', $transfer)->update(['status' => $status, 'received_by' => $request->user()->id,
                'received_device_id' => $request->attributes->get('device')->id, 'received_at' => now(), 'version' => $record->version + 1]);
            $result = ['id' => $transfer, 'status' => $status, 'version' => $record->version + 1, 'transfer_no' => $record->transfer_no];
            app(PostingAudit::class)->record($request, 'transfer.received', 'stock_transfer', $transfer, (array) $record, $result + ['items' => $data['items']]);
            return ApiResponse::data($result);
        });
    }

    public function resolve(Request $request, int $transfer, PostingTransaction $transaction): JsonResponse
    {
        abort_unless($request->user()->can('stock.transfer'), 403);
        $data = $request->validate(['expected_version' => 'required|integer|min:1', 'transfer_item_id' => 'required|integer|min:1',
            'resolution' => 'required|in:recovered,written_off', 'qty' => 'required|string', 'reason' => 'required|string|max:255']);
        return $transaction->run($request, 'transfer.resolve', $data + ['transfer_id' => $transfer], function () use ($request, $transfer, $data) {
            $record = $this->lock($request, $transfer, $data['expected_version']);
            abort_unless($record->status === 'discrepancy' && $record->received_at !== null, 409, 'Only an unresolved received transfer can be resolved.');
            $item = DB::table('stock_transfer_items')->where(['id' => $data['transfer_item_id'], 'transfer_id' => $transfer])->lockForUpdate()->first();
            abort_unless($item, 404);
            $qty = $this->quantity($item->product_id, $data['qty'], false);
            $remaining = BigDecimal::of($item->qty_dispatched)->minus($item->qty_received)->minus($item->qty_recovered)->minus($item->qty_written_off);
            abort_if($qty->isGreaterThan($remaining), 422, 'Resolution quantity exceeds the unresolved shortage.');
            $value = $this->portion($item, $qty);
            $row = ['transfer_item_id' => $item->id, 'resolution' => $data['resolution'], 'qty' => (string) $qty,
                'value' => (string) $value, 'reason' => $data['reason'], 'created_by' => $request->user()->id,
                'device_id' => $request->attributes->get('device')->id, 'resolved_at' => now()];
            $id = DB::table('transfer_resolutions')->insertGetId($row);
            if ($data['resolution'] === 'recovered') {
                $this->stock($request, $item, $qty, $value, ['transfer_resolution_id' => $id], 'transfer_recovery');
            }
            $quantityField = $data['resolution'] === 'recovered' ? 'qty_recovered' : 'qty_written_off';
            $valueField = $data['resolution'] === 'recovered' ? 'recovered_value' : 'written_off_value';
            DB::table('stock_transfer_items')->where('id', $item->id)->update([
                $quantityField => (string) BigDecimal::of($item->$quantityField)->plus($qty),
                $valueField => (string) BigDecimal::of($item->$valueField)->plus($value)]);
            $status = $this->status($transfer, true);
            DB::table('stock_transfers')->where('id', $transfer)->update(['status' => $status, 'version' => $record->version + 1]);
            app(PostingAudit::class)->record($request, 'transfer.'.$data['resolution'], 'transfer_resolution', $id, null, $row, $data['reason']);
            return ApiResponse::data(['id' => $id, 'transfer_id' => $transfer, 'status' => $status, 'version' => $record->version + 1, 'value' => (string) $value], 201);
        });
    }

    private function lock(Request $request, int $transfer, int $version): object
    {
        $branch = $request->attributes->get('branch');
        $query = DB::table('stock_transfers')->where(['id' => $transfer, 'tenant_id' => $request->user()->tenant_id, 'destination_branch_id' => $branch->id]);
        $initial = (clone $query)->first();
        abort_unless($initial, 404);
        Branch::where('tenant_id', $branch->tenant_id)->whereIn('id', [$initial->source_branch_id, $branch->id])->orderBy('id')->lockForUpdate()->get();
        $record = $query->lockForUpdate()->first();
        abort_unless($record && $record->version === $version, 409, 'The transfer changed. Reload it before continuing.');
        return $record;
    }

    private function quantity(int $product, string $input, bool $allowZero): BigDecimal
    {
        $record = DB::table('products')->where('id', $product)->lockForUpdate()->first();
        $decimal = (bool) DB::table('units')->where('id', $record->unit_id)->value('allow_decimal');
        if ($allowZero && preg_match('/\A0(?:\.0{1,3})?\z/', $input)) {
            return BigDecimal::zero()->toScale(3);
        }
        return BigDecimal::of(Money::quantity($input, $decimal));
    }

    private function portion(object $item, BigDecimal $qty): BigDecimal
    {
        $allocatedQty = BigDecimal::of($item->qty_received)->plus($item->qty_recovered)->plus($item->qty_written_off);
        $allocatedValue = BigDecimal::of($item->received_value)->plus($item->recovered_value)->plus($item->written_off_value);
        return BigDecimal::of($item->dispatched_value)->multipliedBy($allocatedQty->plus($qty))
            ->dividedBy($item->qty_dispatched, 2, RoundingMode::HalfUp)->minus($allocatedValue);
    }

    private function status(int $transfer, bool $resolved): string
    {
        $missing = DB::table('stock_transfer_items')->where('transfer_id', $transfer)
            ->whereRaw('qty_dispatched > qty_received + qty_recovered + qty_written_off')->exists();
        return $missing ? 'discrepancy' : ($resolved ? 'resolved' : 'received');
    }

    private function stock(Request $request, object $item, BigDecimal $qty, BigDecimal $value, array $source, string $type): void
    {
        $scope = ['tenant_id' => $request->user()->tenant_id, 'branch_id' => $request->attributes->get('branch')->id, 'product_id' => $item->product_id];
        $projection = app(StockProjection::class);
        $balance = $projection->lock($scope);
        $beforeQty = BigDecimal::of($balance->qty);
        $beforeValue = BigDecimal::of($balance->inventory_value);
        $afterQty = $beforeQty->plus($qty);
        $afterValue = $beforeValue->plus($value);
        if ($beforeQty->isNegative()) {
            $afterValue = $afterQty->isNegative() ? $beforeValue->multipliedBy($afterQty)->dividedBy($beforeQty, 2, RoundingMode::HalfUp)
                : $value->multipliedBy($afterQty)->dividedBy($qty, 2, RoundingMode::HalfUp);
        }
        $projection->append($scope, $source + ['type' => $type, 'qty_change' => (string) $qty,
            'value_change' => (string) $afterValue->minus($beforeValue), 'qty_after' => (string) $afterQty, 'value_after' => (string) $afterValue,
            'valuation_variance' => (string) $afterValue->minus($beforeValue)->minus($value), 'unit_cost' => $item->unit_cost,
            'cost_estimated' => false, 'estimated_qty' => '0.000', 'cost_source' => 'transfer_carried', 'created_by' => $request->user()->id, 'posted_at' => now()], $balance->last_known_unit_cost);
    }
}
