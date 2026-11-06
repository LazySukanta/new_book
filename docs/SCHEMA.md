# SCHEMA.md â€” POS & Inventory â€” v3 draft

Read with SPEC.md and RULES.md. Precedence: RULES > SCHEMA > SPEC. This is a logical schema, not migrations or approval to implement.

## 1. Conventions

MySQL 8, InnoDB, utf8mb4. IDs are server-generated unsigned BIGINT; foreign keys match. All tables have id and UTC created_at; mutable tables also have updated_at. Posted financial/stock records cannot be deleted. Foreign keys RESTRICT historical deletion. Master records use is_active; products may additionally use deleted_at only when no history exists. Owner purchase deletion is a retained soft-delete with compensating entries, not deletion of financial history.

Money type M = DECIMAL(18,2) in INR. Quantity Q = DECIMAL(18,3). Calculation intermediates and average unit cost C = DECIMAL(24,9). Percentages are unsigned integer basis points. API decimal values are strings. No FLOAT/DOUBLE. Explicit validation bounds prevent multiplication and aggregate overflow. Signed values allowed only for documented deltas/reversals/rounding. State codes CHAR(2), GSTIN VARCHAR(15), names VARCHAR(255), notes TEXT, snapshots JSON, statuses VARCHAR(32) with application validation.

## 2. Organization and access

| Table | Columns beyond conventions / relations |
|---|---|
| shop_settings | singleton; name, legal_name, logo_path, footer, gst_registration_type, price_inclusive_default, max_cashier_discount_bp, manager_cancel_window_days, hsn_required, receipt_width_mm; allow_negative_stock BOOLEAN default true (sales only); owner may disable |
| gst_states | code unique, name |
| branches | code unique, name, address, phone, gstin nullable, state_code FK gst_states.code, is_active |
| users | name, email unique, password hash, is_active, has_all_branches, default_branch_id nullable FK branches, remember_token |
| branch_user | branch_id FK, user_id FK; unique pair |
| roles / permissions / model_has_roles / model_has_permissions / role_has_permissions | spatie package schema; application additionally enforces branch scope |
| sessions / personal_access_tokens | Laravel/Sanctum standard schemas; no passwords or tokens in audit JSON |

Manager access never grants owner privileges. Default branch must be accessible. Branch membership is authoritative; role alone does not grant branch access.

## 3. Catalogue and parties

| Table | Columns / relations |
|---|---|
| categories | name unique, is_active |
| units | code unique, name, allow_decimal, is_active |
| tax_rates | name, rate_bp, is_active |
| products | sku unique, barcode nullable unique, name, category_id nullable FK, unit_id FK, tax_rate_id FK, hsn nullable, tax_inclusive, retail_price M, wholesale_price M, purchase_price M (last received purchase cost), reorder_level Q, is_active, deleted_at nullable |
| product_price_slabs | product_id FK, min_qty Q, price M; unique(product_id,min_qty); maximum five per product; wholesale only |
| customers | name, phone nullable, address nullable, state_code nullable FK, gstin nullable, credit_limit M, preferred_sale_type, is_system, is_active, active_phone nullable generated unique key for active phone uniqueness |
| suppliers | name, phone nullable, address nullable, state_code nullable FK, gstin nullable, is_active |

Walk-in is one protected system customer, no credit. Slabs and defaults are global; branch-specific prices are outside scope.

## 4. Sales and returns

sales: branch_id FK, customer_id FK, cashier_id FK users, invoice_no nullable unique, issued_document_id nullable unique FK issued_documents, financial_year, document_type, sale_type, status (held/completed/cancelled/discarded), issued_at nullable, place_of_supply_code nullable FK, is_interstate, price_policy_snapshot JSON, shop_snapshot JSON, branch_snapshot JSON, customer_snapshot JSON, subtotal M, line_discount_total M, bill_discount M, taxable M, cgst M, sgst M, igst M, unrounded_total M, round_off M, total M, paid_amount M, balance_amount M, note TEXT nullable (maximum 1,000 characters), held_pricing_snapshot JSON nullable, held_pricing_fingerprint CHAR(64) nullable, held_cart_snapshot JSON nullable, held_at nullable, held_by nullable FK users, completed_at nullable, completed_by nullable FK users, discarded_at nullable, discarded_by nullable FK users, discard_reason nullable, cancelled_by nullable FK users, cancelled_at nullable, cancellation_reason nullable. Held drafts have no invoice number and use draft values; completion recalculates and transitions this same sale ID to completed. Preserve held snapshots and original held timestamps after completion. Discarded holds remain stored without financial/stock effect.

sale_items: sale_id FK, product_id FK, name_snapshot, sku_snapshot, hsn_snapshot nullable, unit_snapshot JSON, qty Q, unit_price M, price_source, pricing_snapshot JSON, pricing_fingerprint CHAR(64), price_includes_tax, discount_type nullable, discount_bp nullable, line_discount M, bill_discount_share M, taxable M, rate_bp, cgst M, sgst M, igst M, line_total M, unit_cost C, cost_total M, cost_estimated BOOLEAN, cost_source (average/last_purchase/manual_estimate/unknown_zero), estimated_qty Q. Unique(sale_id,product_id).

sale_returns: sale_id FK, branch_id FK, credit_note_no unique, issued_document_id unique FK issued_documents, status (issued/cancelled), cancellation_reason nullable, cancelled_at nullable, cancelled_by nullable FK users, financial_year, issued_at, reason, created_by FK users, taxable M, cgst M, sgst M, igst M, round_off M, total M, credit_adjusted M, refund_amount M, invoice_snapshot JSON.

sale_return_items: sale_return_id FK, sale_item_id FK, qty Q, taxable M, cgst M, sgst M, igst M, line_total M, unit_cost C, cost_total M, cost_estimated BOOLEAN, cost_source, estimated_qty Q. Unique(sale_return_id,sale_item_id). Original sale item must belong to the linked sale.

Invoice snapshots contain historical identity, registration, rates and labels. Live settlement balances are derived caches, not historical invoice fields.

## 5. Purchases and supplier returns

purchases: branch_id FK, supplier_id FK, supplier_invoice_no, normalized_invoice_no, supplier_invoice_date DATE, received_at, cost_includes_tax, status (posted/deleted), current_revision unsigned INT default 1, deleted_at nullable, deleted_by nullable FK users, deletion_reason nullable, discount_amount M, taxable M, cgst M, sgst M, igst M, unrounded_total M, round_off M, total M, paid_amount M, balance_amount M, supplier_snapshot JSON, branch_snapshot JSON, created_by FK users, cancelled_by nullable FK users, cancelled_at nullable, cancellation_reason nullable. Unique(supplier_id,normalized_invoice_no), deliberately stricter than per-year uniqueness in this draft.

purchase_items: purchase_id FK, product_id FK, qty Q, unit_cost M, price_includes_tax, rate_bp, discount_share M, taxable M, cgst M, sgst M, igst M, line_total M, inventory_unit_cost C, product_snapshot JSON. Unique(purchase_id,product_id).

purchase_returns: purchase_id FK, branch_id FK, document_no unique, issued_document_id unique FK issued_documents, issued_at, reason, total M, taxable M, cgst M, sgst M, igst M, round_off M, credit_adjusted M, refund_amount M, created_by FK users, snapshot JSON.

purchase_return_items: purchase_return_id FK, purchase_item_id FK, qty Q, taxable M, cgst M, sgst M, igst M, line_total M, inventory_unit_cost C, inventory_value M. Unique(purchase_return_id,purchase_item_id).

## 6. Stock

| Table | Columns / relations |
|---|---|
| stock_balances | branch_id FK, product_id FK, qty Q, inventory_value M, avg_cost C, last_known_unit_cost C nullable, valuation_provisional BOOLEAN, last_ledger_id nullable FK stock_ledger; unique(branch_id,product_id) |
| stock_adjustments | branch_id FK, reason, note, created_by FK users, posted_at; immutable after posting |
| stock_adjustment_items | adjustment_id FK stock_adjustments, product_id FK, qty_before Q, counted_qty Q nullable, qty_change Q signed, qty_after Q, unit_cost C, value_change M signed, valuation_variance M signed, cost_estimated BOOLEAN, cost_source; unique(adjustment_id,product_id) |
| stock_ledger | branch_id FK, product_id FK, type, qty_change Q signed, value_change M signed, valuation_variance M signed, cost_estimated BOOLEAN, cost_source, unit_cost C, qty_after Q, value_after M, sale_item_id nullable FK, purchase_item_id nullable FK, sale_return_item_id nullable FK, purchase_return_item_id nullable FK, adjustment_item_id nullable FK, purchase_revision_item_id nullable FK purchase_revision_items, transfer_item_id nullable FK, transfer_resolution_id nullable FK, reversal_of_id nullable unique FK stock_ledger, created_by FK users, posted_at |

Each ledger row has exactly one source item or reversal_of_id. Source item branch/product must match. Opening stock also uses adjustment header/items with reason opening. Transfer source may generate separate source and destination rows; enforce unique source event + branch + product + movement type. Ledger is append-only. Balances are transactionally updated projections of summed signed ledger quantities and values.

M09 stores estimated_qty Q and estimated_unit_cost C nullable on shortage ledger rows so the estimated portion remains visible alongside blended unit_cost. M10 adjustments retain device_id, kind and optional corrects_adjustment_id. Adjustment items also retain value_before and value_after. Count requests compare expected_last_ledger_id against the locked balance. An unchanged physical count retains its adjustment and audit evidence without inserting a zero-effect movement.

## 7. Transfers

stock_transfers: source_branch_id FK, destination_branch_id FK (different), status (draft/dispatched/received/discrepancy/resolved), transfer_no unique nullable until dispatch, issued_document_id nullable unique FK issued_documents (required after dispatch), created_by FK users, dispatched_by nullable FK users, received_by nullable FK users, dispatched_at nullable, received_at nullable, note.

stock_transfer_items: transfer_id FK stock_transfers, product_id FK, qty_requested Q, qty_dispatched Q, qty_received Q, qty_written_off Q, qty_recovered Q, unit_cost C, dispatched_value M, received_value M, written_off_value M; unique(transfer_id,product_id).

transfer_resolutions: transfer_item_id FK, resolution (written_off/recovered), qty Q, value M, reason, created_by FK users, resolved_at. Multiple rows permitted up to unresolved shortfall. Dispatch and one final receipt event per transfer in this draft; partial receiving leaves a discrepancy to resolve.

## 8. Payments and party balances

payments: branch_id FK, customer_id nullable FK, supplier_id nullable FK (exactly one), direction (in/out), purpose (receipt/supplier_payment/refund/reversal), mode (cash/upi/card/bank), amount M positive, reference nullable, paid_at (effective time), historical_entry BOOLEAN default false, included_in_opening_entry_id nullable FK party_opening_entries, balance_effect (normal/included_in_opening), status (posted/reversed), reversal_of_id nullable unique FK payments, created_by FK users, reason nullable. Mode credit_adjust is not an actual payment.

payment_allocations: payment_id FK, sale_id nullable FK, purchase_id nullable FK, sale_return_id nullable FK, purchase_return_id nullable FK, party_opening_entry_id nullable FK party_opening_entries (exactly one target), amount M signed, effective_at UTC, created_by FK users. Reversal allocations negate originals. Party and branch must match target. Return targets link actual refund settlement, not a second receivable reduction.

customer balance per branch = signed effective opening entries plus completed, noncancelled sales totals minus linked credit note totals minus effective incoming payments plus effective outgoing customer refunds. Supplier payable per branch = signed effective opening entries plus active purchases minus supplier return totals minus effective outgoing supplier payments plus effective incoming supplier refunds. Include unallocated normal-effect payments once; included_in_opening evidence has zero additional balance effect. Opening credits/advances reduce the balance; dues increase it. A negative customer balance is customer credit; a negative supplier payable is an advance.

Document open balance uses invoice total minus allocated receipts/payments and return credit applied to that invoice. Return credit cannot exceed remaining invoice debt; excess becomes party credit until refunded. paid_amount and balance_amount are rebuildable caches. Never subtract both payment headers and allocations in a party balance. Opening party balances and historical payments are supported through dated entries; never invent historical invoices. See section 10 below.

## 9. Controls and indexes

document_series: branch_id FK, financial_year CHAR(5) (26-27), document_type (tax_invoice/bill_of_supply/commercial_bill/credit_note/purchase_return/stock_transfer/debit_note), prefix CHAR(1), owning_channel VARCHAR(32), digit_width fixed at 5 for every enabled numbered document type, next_number unsigned INT default 1, max_number unsigned INT fixed at 99999, rollover_order unsigned INT, status (reserved/ready/active/exhausted/closed), first_issued_at nullable, exhausted_at nullable, closed_at nullable, created_by FK users. Unique(branch_id,financial_year,prefix) prevents the same prefix being allocated to different types/channels. Unique(branch_id,financial_year,document_type,owning_channel,rollover_order). All prefixes are one uppercase letter excluding I/O. Initial configurable credit-note reservations: X/Y/Z. A branch/FY/prefix belongs to exactly one document type and channel. After exhaustion owner allocates another unused letter; no available letter means issuance blocks. next_number may equal max_number+1 only for exhausted/closed rows. Explicit provisioning per year; no implicit rollover to another channel. No counter reset or post-use ownership change. Allocation changes lock the branch row and affected series in stable ID order.

issued_documents: document_series_id FK document_series, serial_number unsigned INT, document_no VARCHAR(16) unique, document_type, issued_at UTC, status (issued/cancelled), cancelled_at nullable, cancelled_by nullable FK users, cancellation_reason nullable, created_by FK users, registration_snapshot JSON. Unique(document_series_id,serial_number). Immutable identity/number/issue time. Registry record and actual originating document record commit together and cancellation statuses must stay consistent. sales, sale_returns, purchase_returns and dispatched stock_transfers reference the registry; registry type must match the referring record. For stock_transfer, document_series.branch_id is source_branch_id; receipt never allocates another number. debit_note is reserved but disabled until a posting workflow and backing table are designed; it cannot generate registry rows in v1. Exactly one actual document per registry row; cross-table invariant verified by posting service and reconciliation. Reserved future-channel series have no issued_documents rows. No fake documents for unallocated numbers. Index(document_series_id,issued_at,status).

Series labels/prefixes are persisted historical identity, not reconstructed from mutable branch settings. Reports group registry rows with their series, within the requested issue-date period; cancellation status is evaluated as of the report cut-off using cancelled_at. Counts: total includes cancelled, net = total - cancelled. Gap exceptions use persisted serials, not just last-first+1. Credit-note cancellation requires reversal of its stock, customer credit and refund effects; block until dependencies can be safely unwound. Historical returns/cancelled notes remain for audit; active return totals exclude cancelled notes.

idempotency_requests: user_id FK, branch_id FK, route, key, request_hash, response_status, response_body JSON, completed_at nullable; unique(user_id,branch_id,route,key). Posting record and response commit atomically.

audit_logs: user_id nullable FK, branch_id nullable FK, action, entity_type, entity_id, before_json nullable, after_json nullable, reason nullable, request_id, created_at. Append-only, redact secrets.

Indexes: sales(branch_id,issued_at,status), purchases(branch_id,received_at,status), stock_ledger(branch_id,product_id,id), payments(branch_id,paid_at), payments(customer_id,branch_id), payments(supplier_id,branch_id), sales(customer_id,branch_id,status), purchases(supplier_id,branch_id,status), audit_logs(branch_id,created_at), transfers(source_branch_id,status), transfers(destination_branch_id,status). Index all foreign keys and allocation targets. Financial uniqueness is database-enforced. Reconciliation checks aggregate caches; it never rewrites ledger history.

Invoice comments: sales.note is the optional invoice comment; Comment inclusion is chosen in print preview for each thermal/A4 print or PDF export; include_comment is a transient rendering option and has no database column. Owner-only post-finalization changes preserve before/after values and reason in audit_logs. Note is editable presentation metadata, outside immutable financial snapshots.


## 10. Gradual onboarding and dated opening entries

party_accounts: branch_id FK, customer_id nullable FK, supplier_id nullable FK (exactly one), opening_status (not_entered/entered/confirmed_zero), opening_status_effective_at nullable, opening_confirmed_at nullable, opening_confirmed_by nullable FK users. Unique(branch_id,customer_id) and unique(branch_id,supplier_id). No account row is treated as not_entered, never confirmed zero. Balances are derived, not manually stored totals.

party_opening_entries: party_account_id FK, entry_type (due/credit/advance/reversal), signed_amount M (positive increases customer receivable/supplier payable, negative reduces it), effective_at UTC, reference nullable, note, created_by FK users, reversal_of_id nullable unique FK party_opening_entries, replaces_entry_id nullable FK party_opening_entries, reason, status (posted/reversed), created_at actual server timestamp. Entries are append-only, zero opening confirmed through party_accounts with audit rather than fake zero payment. No stock/tax/issued-document reference. Multiple incremental legacy entries permitted with duplicate-reference checks/warnings; never silently overwrite a prior opening balance. Index(party_account_id,effective_at,id).

Payments carry paid_at as financial effective time, actual created_at, historical_entry and optional included_in_opening_entry_id. Included-in-opening evidence must match party/branch and cannot allocate or generate another balance effect. An associated reversal of evidence also has zero effect. Reconciliation includes original and reversal signs exactly once. Normal historical payments may allocate to opening debt or remain advances, with dated allocation records. Allocation effective_at must be at least both payment effective time and target debt effective time; later application of old advance must not rewrite earlier document settlement. FIFO only uses eligible debts as of its application date.

As-of balance/report requires effective_at <= business cut-off AND created_at <= optional recorded_as_of cut-off. Normalized signed reversals use their own effective dates; preserve original rows, do not drop them merely because their status is reversed. Audit status confirmations and changes independently. Opening credit application to an invoice needs party_credit_allocations: party_opening_entry_id FK, sale_id nullable FK, purchase_id nullable FK (exactly one), amount M signed, effective_at, created_by FK users, reversal_of_id nullable unique FK same table. These applications reduce invoice balance only, not party balance a second time; source credit and target debt locked and capped. Payment-backed advances use payment_allocations instead.

## 11. Stock estimate and price snapshot additions

Stock quantity/value projections may be signed while sales stock is negative; valuation_provisional identifies estimates. sale_items records shortage estimated_qty, cost_estimated and cost_source even when no purchase cost exists. All original snapshots are immutable; sale returns copy original cost provenance. Product last purchase cost is not automatically a verified historical sale cost.

Stock correction inventory target = physical_count * explicitly supplied unit cost; value_change = target_value - previous_value. valuation_variance = value_change - (qty_change * unit_cost), rounded to two places. Store variance for separate reporting so a count from negative stock can reach the stated target value without editing old cost of goods sold. Cost-only corrections may have qty_change zero and nonzero value_change with a required reason and cost. Estimated variance is not silently folded into historical sale profit.

sales.held_pricing_snapshot and sales.held_pricing_fingerprint store the held cart pricing basis; sale_items.pricing_snapshot and sale_items.pricing_fingerprint store the per-line basis; completion carries the last acknowledged pricing fingerprint. Compare current server-resolved price/slab/tax before posting. Price history comes from audit_logs filtered by product (including slab/tax changes); do not duplicate current values into a second price table. No scheduled price table in v1.


## 12. Tenant and device binding

M03–M04 enrollment fields: tenants.code is a unique installation identifier. branch_user and device_branch store tenant_id with composite tenant/user, tenant/device and tenant/branch foreign keys. devices additionally stores pairing_hash CHAR(64) nullable unique, pairing_expires_at nullable, paired_at nullable, credential_hash CHAR(64) nullable unique. Codes and credentials use SHA-256 hashes; raw values never enter database/audit. Pairing consumption is locked and clears the code hash. The returning browser stores an encrypted HttpOnly device credential cookie; server sessions retain the tenant/device binding in payload and indexed columns. Session bindings are nullable for unauthenticated CSRF visits. Bearer expiry is non-null at database level.

 tenants: name, is_active; one seeded business per installation in v1. branches, users, products, customers, suppliers, categories, units, tax_rates and shop_settings gain tenant_id FK tenants. shop_settings singleton is per tenant. Branch rows inherit tenant, not separate tenant identity. Owner has_all_branches is tenant-local. All uniqueness keys for business master identifiers become tenant-scoped, except user login email stays globally unique for simple login. Global GST-state reference data stays shared.

 devices: tenant_id FK, device_uuid CHAR(36) unique (server-generated), name, platform (web/windows/mobile), channel, status (active/revoked), registered_by FK users, registered_at, last_seen_at nullable, revoked_at nullable, revoked_by nullable FK users. device_branch: device_id FK, branch_id FK; unique pair, both must share tenant. Owner approval required; mutable branch grants are checked per request.

 personal_access_tokens: standard Sanctum fields plus tenant_id FK and device_id FK, both required for bearer tokens, expires_at required. tokenable user must share tenant with device/token. Token has no independent branch authority. Default expiry 30 days. Device revocation deletes/revokes all its tokens transactionally; token issue/revocation audit stores metadata only, never token contents. Laravel sessions gain tenant_id FK and device_id FK; bind and check each authenticated Livewire/web request. Audit logs retain device_id nullable FK and tenant_id FK.

 All branch-owned transaction roots (sales, purchases, returns, payments, stock movements/adjustments, transfers, party_accounts, document_series, issued_documents and idempotency_requests) carry tenant_id FK. Child rows inherit context from their parent; validate all cross-references share the same tenant and use composite tenant/id foreign keys on cross-root relationships where practicable. No unscoped lookup based on client-provided ID. Tenant-aware indexes and uniqueness include tenant_id; scoped sequence and idempotency uniqueness include it too. Immutable document_no need only be unique per tenant; v1 has one tenant. Token tenant cannot be switched via a header.

 issued_documents and financial transaction headers add device_id FK for issuing/posting device provenance; origin_channel comes from trusted device registration. Historical entries record the device used to enter them, not an invented historical device. These fields prepare future clients without implementing offline sync.

## 13. Pricing fingerprint definition

 Canonical cart snapshot includes sale_type, customer pricing context, ordered product IDs/quantities, selected price source/slab, unit price as two-place string, tax rate/tax-inclusive flags, unit validation metadata and requested line/bill discounts. Stable key order, canonical decimal strings, deterministic line order; hash SHA-256 => CHAR(64). Identical inputs/resolved prices produce identical fingerprints across API/Livewire. Relevant tax-registration/place-of-supply context is included to invalidate a stale preview. Server computes and checks hashes; they are stale-preview tokens, not authorization credentials.

 sales.held_pricing_snapshot JSON and held_pricing_fingerprint CHAR(64) are nullable on normal sales and required for held drafts. sale_items.pricing_snapshot JSON and pricing_fingerprint CHAR(64) are populated when held and finalized; held completion recalculates, preserving held basis in sales.held_cart_snapshot/held_pricing_snapshot and audit when the same sale is finalized. No deletion/recreation. acknowledged_pricing_fingerprint is completion request data, not a missing database column: finalized item snapshots/fingerprints preserve accepted prices. No separate acknowledgement history table needed.


## 14. Complete number-source mapping

| Numbered field | document_series.document_type | Branch/FY source | Allocation event |
|---|---|---|---|
| sales.invoice_no | tax_invoice, bill_of_supply or commercial_bill | issuing branch, issue-date FY | Sale finalized |
| sale_returns.credit_note_no | credit_note | original sale branch, credit-note issue-date FY | Sales return posted |
| purchase_returns.document_no | purchase_return | purchase branch, return issue-date FY | Supplier return posted |
| stock_transfers.transfer_no | stock_transfer | source branch, dispatch-date FY | First dispatch committed |
| Future debit_notes.document_no | debit_note (reserved, disabled) | Not enabled until designed | No issuance in v1 |

All enabled types use the common document_series allocator plus issued_documents registry, tenant/device/channel binding, distinct allocated letters, five-digit counter, transaction lock and same-type/channel rollover. Format remains branch/YY-YY/L00001 (at most 16 characters). No hard-coded PR/DN/TR prefix and no ad-hoc counters on source tables. Each letter is exclusively allocated to one type/channel per tenant/branch/FY; credit-note X/Y/Z reservations remain separate. Provision purchase_return and stock_transfer allocations when enabling those actions; missing allocation blocks that action only. No need to configure unused future types before normal billing.

Supplier invoice numbers on purchases are external references, not numbers issued by this application. A supplier's credit/debit note reference may be recorded as supplier reference evidence; it never consumes our issued series. A purchase_return is an internal supplier-return document; it is not silently treated as a separate sales debit-note workflow. Add supplier_document_no nullable, supplier_document_date nullable to purchase_returns for supplier acknowledgement evidence. Preserve internal document_no separately.

Financial registry rows must be backed by exactly one matching enabled source record. Transfers share the registry for numbering/audit only; do not include them in customer debt, sales totals or GST invoices just because they have an issued number. Registry maps to source FK for purchase returns and transfers as well as sales/credit notes. Future debit notes need their own backing record and financial/GST rules before activation; adding an enum value alone is not feature support.


## 15. Owner purchase versions and correction effects

purchase_revisions: tenant_id FK, purchase_id FK, revision_no unsigned INT, action (create/update/soft_delete), before_snapshot JSON nullable for creation, after_snapshot JSON, reason, created_by FK users, device_id FK devices, correction_posted_at UTC, financial_effective_at UTC, supplier_balance_delta M signed, previous_supplier_id nullable FK suppliers, resulting_supplier_id FK suppliers, allocation_changes_snapshot JSON, idempotency_request_id FK. Unique(purchase_id,revision_no). Immutable complete document/line snapshots preserve each accepted version, including original supplier invoice reference/date. Creation writes revision 1. Purchase current row is a projection of latest version; old revisions never edited or removed. Old purchase_items with references cannot be physically deleted; use is_removed BOOLEAN default false and retain product identity in historical revision snapshots. A replacement product uses a new line ID.

purchase_revision_items: purchase_revision_id FK, product_id FK, purchase_item_id nullable FK, qty_change Q signed, unit_cost C, inventory_value_change M signed, valuation_variance M signed, qty_before Q, qty_after Q, value_before M, value_after M, cost_estimated BOOLEAN, cost_source, reason. Ledger correction links to this item, never overwrites original purchase_item ledger movements. Multiple product effects per revision allowed for a product substitution. No stock effect means no revision-item ledger entry required. Any cost allocation/revaluation basis is saved in revision after_snapshot and shown in owner preview; no historical recosting implied.

Payment headers retain actual money movements. Revision allocation changes use append-only signed payment_allocations with purchase_revision_id nullable FK purchase_revisions for traceability, rather than rewriting/removing original allocations. Refunding requires a distinct actual payment. A supplier reassignment cannot mutate existing payment party identity. Supplier payable derives latest purchase projection (excluding deleted), returns and effective payments, counting correction impact once; revision delta is an audit/reconciliation record, not an additional payable on top of the revised total.

Reports of recorded historical purchase state select latest purchase_revisions.created_at <= recorded_as_of, not today's edited projection; soft deletion preserves all older versions. Stock reports always use ledger posting times. Foreign keys preserve dependencies; orphaning existing returns/allocations is prohibited. Uniqueness of supplier invoice number still covers retained deleted history to prevent silent duplicate re-entry; correcting/restoring a deleted document requires a further audited revision, never physical removal.

## 16. Held-sale persistence contract

sales.status includes held, completed, cancelled and discarded. sales.held_cart_snapshot JSON stores original saved lines/quantities/notes and pricing basis independently of current sale_items projections; held_pricing_snapshot/fingerprint remain retained after completion. held_at/held_by and completed_at/completed_by distinguish save and finalization times. discarded_at/discarded_by/discard_reason record logical discard. Completed held sale keeps identical sales.id; no consumed flag or replacement record needed. Only held status can transition to completed/discarded. Hold edits update saved snapshots with before/after audit, and completion retains the final saved hold version plus its edit history.
