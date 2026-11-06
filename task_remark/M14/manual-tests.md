# M14 — Tests you can perform

M14 is an API milestone. The purchase editing screen comes in M31; the existing mockup is not a working purchase screen.

## 1. Easiest check: run the automated examples

Open PowerShell in `C:\Users\local User\Desktop\new_book\application` and paste:

```powershell
$phpBin = (Get-ChildItem 'C:/Users/local User/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.5*/php.exe' | Select-Object -First 1).FullName
$env:Path = (Split-Path $phpBin) + ';' + $env:Path
& $phpBin artisan test --filter='PurchaseCorrectionTest|PurchaseRevisionTest'
```

Expected: all tests pass. These tests use the dedicated `khatapos_test` database and generate their own examples. They do not use your development purchase records. MySQL must be running; the root README describes how to restart this workstation's existing server.

For the whole project, run `& $phpBin artisan test`. For a read-only check of development stock, run `& $phpBin artisan stock:reconcile`; expected: `Stock balances match the ledger.`

## 2. Hands-on API test

Use a local development database and disposable test products/suppliers. The following requests create real development records whose history is deliberately retained. A 0% rate below is a calculation example, not a tax recommendation.

Start the application in PowerShell using the PHP variable from step 1:

```powershell
& $phpBin artisan serve --host=127.0.0.1 --port=8000
```

Keep that terminal open. Use an API client such as Postman with base URL `http://127.0.0.1:8000/api/v1`. In the examples, replace every `<...>` placeholder with your own value. Numeric IDs must be JSON numbers, without quotes. Amounts and quantities must be strings.

### Login and headers

If the owner is already set up, run `& $phpBin artisan shop:pair-device` in another terminal, entering the owner's credentials when prompted. For a fresh installation only, run `shop:setup` instead. Use the resulting one-time code in:

`POST /auth/token`

```json
{
  "email": "<owner email>",
  "password": "<owner password>",
  "pairing_code": "<fresh pairing code>"
}
```

Copy `data.token`. For subsequent requests set:

- `Authorization: Bearer <token>`
- `Accept: application/json`
- `Content-Type: application/json`
- `X-Branch-Id: <branch ID>` — retrieve available IDs with `GET /branches`.

For purchase posting and every correction commit, also set a distinct `Idempotency-Key`, such as `manual-m14-create-01` or `manual-m14-edit-01`. Reuse a key only when intentionally retrying the identical operation. Previews do not require this key. Keep tokens and passwords out of screenshots and saved project files.

### Create isolated example records

1. `POST /suppliers` with `{"name":"M14 manual test supplier"}`. Save `data.id` as the supplier ID.
2. Get a valid unit ID from `GET /units` and tax-rate ID from `GET /tax-rates`. If absent, create them using `POST /units` with `{"code":"TPCS","name":"Test piece","allow_decimal":false}` and `POST /tax-rates` with `{"name":"M14 zero-rate fixture","rate_bp":0}`.
3. `POST /products` with the following body. Save its `data.id` as the product ID. Use a fresh SKU each time you repeat this setup.

```json
{
  "sku": "M14-MANUAL-01",
  "name": "M14 manual test book",
  "unit_id": <unit ID>,
  "tax_rate_id": <tax-rate ID>,
  "tax_inclusive": false,
  "retail_price": "150.00",
  "wholesale_price": "140.00",
  "purchase_price": "100.00"
}
```

### Test A — ₹1,000 purchase, ₹800 paid

`POST /purchases`, using key `manual-m14-create-01`:

```json
{
  "supplier_id": <supplier ID>,
  "supplier_invoice_no": "M14-MANUAL-BILL-01",
  "supplier_invoice_date": "2026-11-05",
  "cost_includes_tax": false,
  "input_tax_recoverable": true,
  "tax_type": "intra",
  "items": [
    {"product_id": <product ID>, "qty": "10", "unit_cost": "100.00", "rate_bp": 0}
  ],
  "payments": [{"mode": "bank", "amount": "800.00"}]
}
```

Expected: HTTP 201, total `1000.00`, paid `800.00`, balance `200.00`, revision 1. Save `data.id` as the purchase ID. The new product has 10 units with inventory value ₹1,000. The historical supplier invoice date does not backdate stock.

### Test B — Correct quantity from 10 to 6

`POST /purchases/<purchase ID>/revision-preview`:

```json
{
  "expected_revision": 1,
  "reason": "Manual test: quantity was entered incorrectly",
  "items": [
    {"product_id": <product ID>, "qty": "6", "unit_cost": "100.00", "rate_bp": 0}
  ]
}
```

Expected preview:

- `after.total`: `600.00`.
- `effects[0].qty_change`: `-4.000`.
- `effects[0].inventory_value_change`: `-400.00`.
- `settlement.retained_allocation`: `600.00`.
- `settlement.advance_to_original_supplier`: `200.00`.

Preview alone must leave revision 1 and stock unchanged. Check with `GET /purchases/<purchase ID>` and `GET /stock/ledger?product_id=<product ID>`.

Copy `data.preview_hash`. Send **the same body plus** `"preview_hash":"<copied hash>"` to `PATCH /purchases/<purchase ID>` with key `manual-m14-edit-01`.

Expected: revision 2, total and paid allocation both `600.00`, purchase balance `0.00`, stock 6 units worth ₹600. `GET /suppliers/<supplier ID>/ledger` shows `meta.balance` of `-200.00`: the supplier holds ₹200 of your money as an advance. No refund or second cash payment is created.

### Test C — Retry and stale edit

1. Repeat the exact PATCH with the same key. Expect the same successful response and revision still 2.
2. Repeat that body with a new key. Expect HTTP 409, `PURCHASE_REVISION_CONFLICT`.
3. Change the reason but reuse the successful key. Expect HTTP 409, `IDEMPOTENCY_CONFLICT`.

### Test D — Soft-delete the corrected purchase

Preview this body at the same preview endpoint:

```json
{
  "expected_revision": 2,
  "action": "soft_delete",
  "reason": "Manual test: remove this purchase from active records"
}
```

Expected: removal of **6**, not the original 10 units. Copy the new preview hash, add it to this body, and send `DELETE /purchases/<purchase ID>` with key `manual-m14-delete-01`.

Expected:

- Revision 3, status `deleted`, zero active stock from this purchase.
- `GET /purchases` excludes it; `GET /purchases?status=deleted` includes it for the owner.
- `GET /purchases/<purchase ID>/revisions` retains all three versions, their reasons, before/after details and effects.
- Supplier ledger balance is `-800.00`. The original bank payment still exists; deletion does not invent a refund.
- A second deletion with a new key is rejected. `POST /purchases/<purchase ID>/cancel` is an alias for the same deletion workflow and needs the same preview requirements.

## 3. Additional checks

Use a separate fresh purchase/product for each scenario so the expected amounts stay clear.

| Test | Action | Expected |
|---|---|---|
| Metadata only | Preview and commit an invoice-number change without `items` | Revision increases; stock and payments do not change. |
| Cost correction after consumption | Start with 10 at ₹100, remove 4 through the stock adjustment API, then change cost to ₹110 with `confirmed_remaining:"6"` on the line | Stock stays 6; value rises from ₹600 to ₹660; variance is `-40.00`. Existing issue cost remains unchanged. Automated tests exercise the sale-issue service directly. |
| Missing confirmation | Change cost without `confirmed_remaining` | HTTP 422; nothing changes. For a wholly consumed purchase, explicitly confirm `"0"`. |
| Insufficient stock | Leave 1 unit, then attempt a quantity reduction of 2 | HTTP 422 explaining negative stock; no revision or balance changes. |
| Stock changed after preview | Preview a quantity edit, post another stock movement, then commit the old preview | HTTP 409 `PURCHASE_PREVIEW_CHANGED`; review a new preview. |
| Payment changed after preview | Preview a correction, post an additional payment, then commit the old preview | HTTP 409 `PURCHASE_PREVIEW_CHANGED`. |
| Supplier change | Change only `supplier_id` to a second test supplier | Original supplier keeps the actual payment as advance; new supplier has full purchase debt; stock does not change. |
| Staff permissions | Use a Manager or Cashier token to preview, edit, delete or read revisions | HTTP 403. Owner role alone does not bypass device/branch restrictions. |
| Historical ledger | Save a UTC timestamp before a correction; request supplier ledger with `recorded_as_of=<timestamp>` | Earlier purchase version and earlier balance are preserved. URL-encode the timestamp. |

When supplying `items`, include the complete intended active item list; omitting an existing product removes that line through a retained correction. Removed lines remain in revision snapshots. Changes are checked against the current branch and supplier, and corrected invoice references must still be unique, including soft-deleted purchases.

Supplier-return dependencies are covered by fixtures only in R1. Actual supplier returns and their integration are scheduled for M22. Browser testing of the purchase correction screen belongs to M31.
