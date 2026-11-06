<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PurchaseRevisionHistory
{
    /**
     * The caller must lock the purchase and keep this append in the same
     * transaction as its projection, stock and supplier allocation writes.
     */
    public function append(User $actor, int $purchase, int $expected, array $revision, array $items = []): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Purchase history must be appended inside the posting transaction.');
        }
        $latest = DB::table('purchase_revisions')->where(['tenant_id' => $actor->tenant_id, 'purchase_id' => $purchase])
            ->orderByDesc('revision_no')->lockForUpdate()->first();
        $current = $latest?->revision_no ?? 0;
        if ($revision['action'] === 'create') {
            abort_unless($actor->is_active && $actor->can('purchases.manage'), 403);
            abort_if($current !== 0 || $expected !== 0, 409, 'Initial purchase revision already exists.');
        } else {
            app(PurchaseCorrectionPreview::class)->assertCurrent($actor, $expected, $current);
            abort_if($current === 0 || $latest->action === 'soft_delete', 409, 'This purchase is unavailable for correction.');
        }

        $id = DB::table('purchase_revisions')->insertGetId([
            'tenant_id' => $actor->tenant_id, 'purchase_id' => $purchase, 'revision_no' => $current + 1,
            'action' => $revision['action'], 'before_snapshot' => isset($revision['before_snapshot'])
                ? json_encode($revision['before_snapshot'], JSON_THROW_ON_ERROR) : $latest?->after_snapshot,
            'after_snapshot' => json_encode($revision['after_snapshot'], JSON_THROW_ON_ERROR),
            'reason' => $revision['reason'], 'created_by' => $actor->id, 'device_id' => $revision['device_id'] ?? null,
            'correction_posted_at' => now(), 'financial_effective_at' => $revision['financial_effective_at'],
            'supplier_balance_delta' => $revision['supplier_balance_delta'],
            'previous_supplier_id' => $revision['previous_supplier_id'] ?? null,
            'resulting_supplier_id' => $revision['resulting_supplier_id'] ?? null,
            'allocation_changes_snapshot' => json_encode($revision['allocation_changes_snapshot'] ?? [], JSON_THROW_ON_ERROR),
            'idempotency_request_id' => $revision['idempotency_request_id'] ?? null,
        ]);
        if ($items !== []) {
            DB::table('purchase_revision_items')->insert(array_map(fn (array $item) => [
                ...$item, 'purchase_revision_id' => $id, 'reason' => $revision['reason'],
            ], $items));
        }

        return $id;
    }
}
