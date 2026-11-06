<?php

namespace App\Services;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class PurchaseCorrectionPreview
{
    public function assertCurrent(User $owner, int $expected, int $current): void
    {
        abort_unless($owner->is_active && $owner->hasRole('Owner') && $owner->can('purchases.revise'), 403);
        abort_if($expected !== $current, 409, 'The purchase revision changed. Generate a new preview.');
    }

    /**
     * Accept server-derived purchase and stock values. The confirmed remainder
     * is the quantity attributable to the retained portion of this purchase.
     */
    public function line(array $before, array $after, array $balance, ?string $confirmedRemaining): array
    {
        $oldQty = BigDecimal::of($before['qty']);
        $newQty = BigDecimal::of($after['qty']);
        $returned = BigDecimal::of($before['returned_qty']);
        $onHand = BigDecimal::of($balance['qty']);
        $inventory = BigDecimal::of($balance['value']);
        $oldCost = BigDecimal::of($before['unit_cost']);
        $newCost = BigDecimal::of($after['unit_cost']);
        $delta = $newQty->minus($oldQty);
        if ($newQty->isLessThan($returned)) {
            throw ValidationException::withMessages(['qty' => 'The revised quantity cannot be below the returned quantity.']);
        }
        if ($delta->isNegative() && $onHand->plus($delta)->isNegative()) {
            throw ValidationException::withMessages(['qty' => 'This correction would leave negative stock. Reconcile the stock first.']);
        }

        $retained = BigDecimal::min($oldQty, $newQty)->minus($returned);
        $costDelta = $newCost->minus($oldCost);
        $verified = BigDecimal::zero();
        if (! $costDelta->isZero() && $retained->isPositive()) {
            if ($confirmedRemaining === null || ! preg_match('/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/', $confirmedRemaining)) {
                throw ValidationException::withMessages(['confirmed_remaining' => 'Confirm the remaining units before applying the historical cost correction.']);
            }
            $verified = BigDecimal::of($confirmedRemaining);
            $available = BigDecimal::max(BigDecimal::zero(), BigDecimal::min($onHand, $onHand->plus($delta)));
            if ($verified->isGreaterThan(BigDecimal::min($retained, $available))) {
                throw ValidationException::withMessages(['confirmed_remaining' => 'Confirmed units exceed the retained purchase quantity or available stock.']);
            }
        }

        $qtyValue = BigDecimal::zero();
        if ($delta->isPositive()) {
            if ($onHand->isNegative()) {
                $closingQty = $onHand->plus($delta);
                $closingValue = $closingQty->isNegative()
                    ? $inventory->multipliedBy($closingQty)->dividedBy($onHand, 2, RoundingMode::HalfUp)
                    : $closingQty->multipliedBy($newCost);
                $qtyValue = $closingValue->minus($inventory);
            } else {
                $qtyValue = $delta->multipliedBy($newCost);
            }
        } elseif ($delta->isNegative()) {
            $qtyValue = $inventory->multipliedBy($delta)->dividedBy($onHand, 2, RoundingMode::HalfUp);
        }

        $valueDelta = $this->money($qtyValue->plus($verified->multipliedBy($costDelta)));
        $closing = $inventory->plus($valueDelta);
        if ($onHand->plus($delta)->isPositive() && $closing->isNegative()) {
            throw ValidationException::withMessages(['confirmed_remaining' => 'The correction would leave negative carrying value. Reconcile the valuation first.']);
        }
        $acquisitionDelta = $this->money($newQty->minus($returned)->multipliedBy($newCost))
            ->minus($this->money($oldQty->minus($returned)->multipliedBy($oldCost)));

        return [
            'confirmed_remaining' => (string) $verified->toScale(3),
            'qty_before' => (string) $onHand->toScale(3),
            'qty_change' => (string) $delta->toScale(3),
            'qty_after' => (string) $onHand->plus($delta)->toScale(3),
            'value_before' => (string) $inventory->toScale(2),
            'inventory_value_change' => (string) $valueDelta,
            'value_after' => (string) $closing->toScale(2),
            'valuation_variance' => (string) $valueDelta->minus($acquisitionDelta)->toScale(2),
        ];
    }

    public function settlement(int $oldSupplier, int $newSupplier, string $oldTotal, string $newTotal, string $appliedPaid, bool $hasReturns): array
    {
        $old = BigDecimal::of(Money::amount($oldTotal));
        $new = BigDecimal::of(Money::amount($newTotal));
        $paid = BigDecimal::of(Money::amount($appliedPaid));
        $changedSupplier = $oldSupplier !== $newSupplier;
        if ($changedSupplier && $hasReturns) {
            throw ValidationException::withMessages(['supplier_id' => 'Resolve linked supplier returns before changing the supplier.']);
        }

        $retained = $changedSupplier ? BigDecimal::zero() : BigDecimal::min($paid, $new);

        return [
            'previous_supplier_id' => $oldSupplier,
            'resulting_supplier_id' => $newSupplier,
            'previous_payable' => (string) $old->minus($paid)->toScale(2),
            'resulting_payable' => (string) $new->minus($retained)->toScale(2),
            'retained_allocation' => (string) $retained->toScale(2),
            'advance_to_original_supplier' => (string) $paid->minus($retained)->toScale(2),
        ];
    }

    private function money(BigDecimal $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HalfUp);
    }
}
