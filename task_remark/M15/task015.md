# task015 — Customers and opening credit accounts

Customer records support search by name or phone, quick creation, preferred retail or wholesale pricing, credit limits, and deactivation. Active phone numbers are normalized and unique within the shop. Staff with create-only permission can register a customer with the default pricing and credit settings. Each shop has one protected Walk-in record. It cannot be edited, deactivated, or used for opening balances or standalone receipts.

Opening dues, credits, and advances are dated financial entries. Owners can confirm a zero opening or reverse an eligible entry with a reason and post a replacement. Historical receipts preserve their effective date and actual entry time. Evidence linked to an opening entry has zero additional balance effect. Ordinary receipts allocate to eligible opening dues, and unapplied funds remain customer credit. The ledger uses the shared financial posting transaction and signed entries. No invoice or stock movement is created by an opening entry or receipt.

The branch ledger distinguishes `not_entered`, `entered`, and `confirmed_zero`. It supports effective and recorded date cutoffs. The balances endpoint lists the recorded opening and net balance for each branch that both the user and the device can access. Its totals identify incomplete opening data and partial branch coverage. The ledger exposes the effective dates of opening debt. Overdue classification needs a due-date policy, and credit-limit enforcement when posting sales belongs to M19.

The migration adds customers and customer links to the shared party-account and payment tables. Exactly one supplier or customer is required for each account and payment. Existing shops get their Walk-in record during migration, and the shop seeder provides it on a fresh installation.

## API and test

- `GET /api/v1/customers`, `POST /api/v1/customers`, `GET /api/v1/customers/{id}`, and `PATCH /api/v1/customers/{id}` manage the customer list and record.
- `GET /api/v1/customers/{id}/ledger` reads the selected branch. `GET /api/v1/customers/{id}/balances` summarizes authorized branches for users who manage customers.
- `POST /api/v1/customers/{id}/opening-entries`, `POST /api/v1/customers/{id}/opening-entries/{entry}/reverse`, and `POST /api/v1/customers/{id}/opening-status/confirm-zero` are owner operations.
- `POST /api/v1/customers/{id}/receipts/preview` previews a receipt. `POST /api/v1/customers/{id}/receipts` records it. For an additional historical receipt, review the preview and submit `confirm_balance_effect: true`. Evidence already included in an opening balance uses `included_in_opening_entry_id`.

Use an authorized device and branch header for branch operations. Financial writes require an idempotency key. Run `php artisan test --filter=CustomerOpeningTest` from the application directory with MySQL running to reproduce the customer workflow on the dedicated test database.

Next is **M16 — Customer settlement and reconciliation**, which covers split allocation, dated application of credit, and receipt reversals. Browser customer-account screens come later.
