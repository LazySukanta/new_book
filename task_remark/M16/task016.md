# task016 — Customer settlement and reconciliation

Customer receipts now support automatic dated FIFO allocation, a specified split across opening dues, or keeping the funds unapplied. A split commits with its receipt in one transaction. Any unallocated amount remains available for later application. Applying an existing receipt or opening credit does not create a second cash movement or change the net party balance again.

Applications are scoped to one customer and branch. Their effective dates cannot precede the funds or the debt. Capacity is checked against the signed allocation timeline so a backdated application cannot consume funds or debt already committed later. Opening credit and receipt allocations share the target capacity.

Reversals retain the original records and append opposite entries. Unapply later receipt allocations before reversing their receipt. Unapply opening credit before reversing its source or target. Receipts recorded as evidence already included in opening retain zero balance effect on reversal. Historical receipt reversal requires the owner.

## Test it

Run `php artisan test --filter=CustomerSettlementTest` from the application directory with MySQL running. The test uses the dedicated database and creates its own examples.

1. Post a ₹1,000 opening due and a ₹400 receipt. The ledger shows ₹600 outstanding.
2. Split a receipt across two dues. The initial response lists the allocated amounts. A split over the receipt total fails without posting any portion.
3. Apply the remaining receipt to another due. The net ledger balance stays the same. Reverse the application before reversing the receipt.
4. Apply a ₹200 opening credit to a due. The credit was counted when entered, so applying or unapplying it leaves the net balance unchanged.
5. Reverse an evidence-only receipt and inspect the ledger. Both the original and reversal have zero balance effect.

## API

Paths below are relative to `/api/v1/customers/{id}`. Supply an authorized branch header. Financial writes require an idempotency key.

| Method and path | Body or result |
|---|---|
| `POST /receipts` | To split, include `allocations: [{"opening_entry_id": 12, "amount": "20.00"}]`. Any remainder stays unapplied. Omit the list for FIFO, or use `auto_allocate: false` to retain all funds. |
| `GET /receipts/{receipt}/allocations` | Allocation records for that receipt. |
| `POST /receipts/{receipt}/apply` | Opening due ID in `opening_entry_id`, decimal string `amount`, optional owner-supplied `effective_at`. |
| `POST /receipts/{receipt}/allocations/{allocation}/reverse` | `reason`. |
| `POST /receipts/{receipt}/reverse` | `reason`. |
| `POST /opening-entries/{credit}/apply-credit` | Opening due ID in `opening_entry_id`, decimal string `amount`, optional `effective_at`. Owner only. |
| `POST /opening-entries/{credit}/credit-allocations/{allocation}/reverse` | `reason`. Owner only. |

Current settlement targets are opening dues. Customer sale invoices and their settlement integration come with M19. M16 uses opening-due records for the settlement demonstration. No browser settlement screen or sale invoice workflow is included here.

Next is **M17 — Pricing/GST preview and fingerprints**.
