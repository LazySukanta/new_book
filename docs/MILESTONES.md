# Build and test milestones

Prepared: 4 November 2026. M01–M17 implemented and verified. Later milestones remain pending.

## How to use this plan

Build one milestone, run its checks, demonstrate it, record the result below, and stop at that boundary. Do not mark a milestone complete because files or endpoints exist. Start the next item in the applicable release sequence only after its prerequisites pass. Milestone numbers identify work, not a requirement to implement every lower-numbered feature first. Use the release dependencies below; deferred features must not block an unrelated Release 1 feature. No calendar duration is promised.

The source of truth remains [RULES.md](RULES.md), then [SCHEMA.md](SCHEMA.md), then [SPEC.md](SPEC.md). This plan expands SPEC's phases 0–8 into two releases, with backend work before its corresponding production screen in each release. All application code, web assets, API endpoints and tests stay together in `application/`. Before a feature has its production screen, demonstrate it using authenticated API requests, service tests or commands, not temporary duplicate UIs. A screen slice means a functional part of the final Livewire website, not a throwaway implementation.

Repository preparation and foundation implementation are complete: Laravel 13, local Git main branch, installed dependencies, separate MySQL development/test databases, docs and preserved mockups. Task remarks and actual test evidence are indexed in [task_remark](../task_remark/README.md).

## Implementation handoff

**Direction.** One Laravel application, one MySQL database and one browser UI fit this shop. Most complexity comes from keeping stock, money and correction history consistent. Keep the server operable on shared hosting using ordinary HTTP requests, built static assets and scheduled backups. The R1 split is viable if the following sequencing details are observed.

| Dependency found during review | How to build it |
|---|---|
| Product import precedes the stock ledger | M08 accepts catalogue data. M10 completes and retests opening quantities through the ledger with explicit unit cost. Mark the stock-import slice pending until then. |
| Purchase posting needs tax arithmetic before the sale calculator | Implement reusable purchase-required arithmetic and approved tax cases in M13. Extend that same calculation in M17. Verify the M13 purchase examples again after M17. |
| Purchase revisions, allocations and stock rows refer to documents built later | Introduce the tables and constraints needed for each working path together. Use service fixtures for later workflows. Provide foreign keys before enabling writes that depend on them. |
| Reports and hardening descriptions mention both releases | Apply the release table to **every** build, check and demo. In R1 demonstrate sale → customer return → collection → purchase correction → basic report. Add supplier return, transfer, Excel and advanced reports in R2. Test R1 restoration using the reports actually shipped. |
| The mockup and feature specs have different jobs | Use the mockup for layout, colors, spacing, navigation patterns and screen composition. Use the agreed release and business rules for data, permissions, actions and amounts. See the screen map below. |

**Instructions for the agent assigned the next milestone**

1. Read its release slice, decision dependencies, implementation order, checks and demo. Open the referenced source-doc sections and inspect current files under `application/`. Record the milestone ID and the observable outcome before changing code.
2. Identify the normal entry point, tables affected and one failure case. Use Laravel migrations/models, validation, policies and shared posting services where needed. Put HTTP handlers under `app/Http/`, services under `app/Services/`, Livewire screens under `app/Livewire/` and Blade under `resources/views/`, relative to the application. Reuse existing code before creating another class.
3. Implement only that slice. Keep Livewire and API on the same business operations. Add a dependency only for a concrete requirement. Avoid a separate frontend server, internal HTTP calls, queues and Redis as deployment prerequisites. A full SaaS tenant framework, event bus, offline replica or second accounting system is outside the plan.
4. When rules disagree, apply RULES > SCHEMA > SPEC and document the discrepancy. If the sources do not determine a financial, tax, authorization or historical-data outcome, record **Needs decision** with a worked example and ask the owner. Never substitute a mock value or a passing fixture for the missing rule.
5. Exercise the normal entry point on the dedicated test environment. Record the command, actual output/result, demonstration and any pending check in the progress ledger. For a posting operation include before/after quantity, value, balance and document count as applicable. If runtime, hardware or owner review is missing, record precisely which acceptance check is still open.
6. Stop at the milestone boundary. Handoff must name the changed files, verified behavior, open decisions and next eligible milestone. Preserve the original requirement docs and mockups unless an agreed change specifically requires editing them.

**Performance checks during implementation.** Paginate lists at the database, filter by tenant and branch before aggregation, fetch only displayed columns and related rows, and inspect query counts on representative pages. Keep print rendering, external calls and slow exports outside posting transactions. Keep validations that depend on current balances or prices inside the lock. Measure the specified 20,000-product search and ten-line sale targets against the same data before and after tuning. Add indexes for measured query patterns. Correctness and repeatable latency measurements are the acceptance evidence.

**Frontend fidelity.** Use the existing [mockup](../mockups/interactive-website/index.html) and its [screen definitions](../mockups/interactive-website/app.js) as the visual reference. Keep the wide sidebar and branch area, navy/blue palette, two-panel desktop POS and responsive navigation. Replace each preview-only toast or static sample with a working action for the relevant release. In the POS, selecting a customer must return to the current bill with its cart intact. Use the agreed same-ID hold flow, two-place amounts and real invoice series. For a new screen absent from the mockup, reuse its nearest list/detail/form pattern. Compare screenshots and complete a keyboard-only trading flow before accepting the website.

## Release plan

Release 1 is the go-live scope; Release 2 adds the deferred operational/reporting capabilities. This split changes delivery order, not ledger/security/history invariants. All retained requirements stay in their source documents. A feature is released only after its API, required screen and release-specific hardening checks pass.

| Work | Release 1 — go-live | Release 2 |
|---|---|---|
| Core backend | M01–M10, M11, M12–M19, M20a/M20b/M20c, M21 | Regression of core when changed |
| Purchases | Posting and owner correction/history: M11/M13/M14 | Supplier returns: M22 |
| Branch transfers | Deferred unless needed for day-one operations | M23–M24; move both plus their screens/tests into R1 if required |
| Basic reporting | R1 slice of M25: sales, stock, low stock, party dues, payments, provisional gross profit and dashboard | M25 transfer/in-transit/discrepancy extensions |
| Advanced reporting | Deferred public endpoints/screens/exports | M26 GST/series/as-of reports; M27 Excel exports and audit viewer |
| Website | M28–M30, R1 slice of M31, M32–M34, R1 slice of M35 | Remainder of M31/M35 for supplier returns, transfers and advanced reports/export/audit |
| Release quality | R1-scoped M36–M39, including backups/restore/deployment and GST-profile acceptance | Repeat M36–M39 for new features and affected R1 regressions |

**Why M11 and screen slices are included:** M14 (already in the proposed M12–M21 range) needs M11's correction design/version primitives. Purchase entry/correction/history requires M31; basic reports need M35's report/dashboard screen. Those are required R1 slices, not extra features. All three M20 submilestones are included in the M12–M21 range.

### Release 1 sequence

1. Foundation/catalogue/stock: M01–M10.
2. Purchase correction groundwork, suppliers and purchases: M11–M14.
3. Customers, settlement, pricing, numbering and sales: M15–M19.
4. Holds, invoice JSON, sales returns and cancellations: M20a → M20b → M20c → M21.
5. Basic operational reports: M25-R1. No dependency on M22–M24.
6. Final website slices: M28 → M29 → M30 → M31-R1 → M32 → M33 → M34 → M35-R1.
7. R1 hardening and go-live decision: M36-R1 → M37-R1 → M38-R1 → M39-R1.

No application-wide onboarding lock is added. The shop may go live with incomplete product/opening financial data after release acceptance, as already agreed.

### Release 2 sequence

1. Supplier returns: M22; add its purchase screens from M31-R2.
2. Transfers: M23 → M24; add transfer and discrepancy screens from M35-R2.
3. Extend M25 for transfers/in-transit/discrepancies; implement M26 and M27; add report/export/audit screens from M35-R2.
4. Repeat M36–M39 for R2 and regressions of deployed R1. Rehearse upgrade migrations/backups on a copy of real-shaped data, never reset the live database.

If transfers are required on day one, move M23/M24, M25's transfer slice and M35's transfer screens before M36-R1; include all receipt/discrepancy/value-conservation tests. Do not dispatch goods through a partially implemented transfer feature.

### Slice boundaries and dependency rules

- M11/M14 must validate the future supplier-return dependency rules in fixtures, but R1 does not expose supplier-return creation. Add/recheck real integration in R2 M22. No fake return workaround for purchase corrections.
- M18 provides registry/type/allocator groundwork needed by invoices and credit notes. R1 does not issue purchase-return/transfer numbers merely because their types exist; those posting actions are disabled until M22/M23. X/Y/Z allocations remain configurable credit-note reservations.
- M25-R1 includes daily/product/cashier sales, stock valuation/low-stock, customer/supplier outstanding, payments, provisional gross profit and dashboard. Its collections/return/revision math is complete; transfer metrics are absent until transfers exist, rather than presented as falsely verified zeroes.
- M31-R1 includes supplier purchase entry, correction preview, history and soft deletion; omit supplier-return actions until R2. M35-R1 includes basic report/dashboard filters and views; omit transfer/advanced-report/export/audit-viewer controls until R2.
- Audit capture, owner purchase-version history, registry integrity and timestamped financial history are mandatory in R1. Only the general audit viewer and advanced reporting presentation are deferred. Keep R1 purchase history usable without M27.
- M12/M16 retain dated opening entries, payment/application history, recorded_as_of groundwork and tests in R1; the cross-module advanced report endpoints/UI remain M26 in R2. Do not lose historical data and attempt to reconstruct it later.
- GST calculation, GST-compliant invoice content and shop/accountant acceptance stay in R1. Only GST report/export screens are deferred. No tax-filing integration is added.
- R1 browser features include invoice comments with print-time inclusion, held-price confirmation, retained held IDs, negative-stock estimates, dated dues/advances, and owner purchase edit/delete history. None is silently postponed by the release split.
- R1 regression scope excludes deferred posting features except fixtures proving shared invariants. M37 backup/restore and M38 deployment remain mandatory. Performance is measured on released paths. Physical scanner/printer/phone checks and owner acceptance are required before actual go-live.
- Mark partial milestones as R1 slice passed / R2 remaining. Do not mark full M25/M31/M35 or shared M36–M39 permanently complete after only R1. Log evidence under both release cut-offs in the existing progress ledger.

## Skills and tools

- Already installed: Impeccable, UI/UX Pro Max, Emil Design Engineering, Apple Design, daisyUI, ask-sonner, Pick UI Library, Break UI, Prototype, Mobile Native, Ponytail and Write Swift.
- Installed for this request: the official `playwright` skill. It is available on the next turn. A skill is not a Playwright MCP server; no Playwright MCP connection was discovered in this session. Verify working browser automation during M01 and M28; do not claim browser tests passed without actually running them.
- Use Ponytail for small complete implementations. Use the UI skills for applicable screens, not as a reason to add features or packages. Follow daisyUI's rules when writing Blade/HTML. Keep existing mockup identity unless a redesign is explicitly agreed.
- Sonner is a React library; do not add React solely for toasts to this Livewire app. Select a compatible feedback mechanism when building the UI. Prototype/Pick UI Library run when explicitly requested; Write Swift waits for future native Apple work. Break UI informs edge-case testing, not a permanent demo-data toggle in production without a request.
- Skill installation does not install PHP, Laravel packages, browser binaries or MCP servers. Verify those independently at their milestones.

## Common completion gate

Every milestone must have: a working build scope; focused meaningful automated checks; one repeatable demonstration; no unresolved failures in its changed area; and a short entry in the progress table. Financial/stock/concurrency tests run on a dedicated MySQL 8 test database, never production or SQLite. Only reset disposable test databases. Run the relevant prior regression tests when shared behavior changes.

Every posting operation must verify tenant/device/branch/role, decimal validation, transaction atomicity, immutable source history and retry/idempotency. In addition to happy-path tests, test a relevant denial and failure/rollback path. Do not write tests that only repeat trivial implementation. Concurrency checks use separate DB connections/processes and a controlled overlap.

Browser milestones require loading/empty/error states, inline validation, keyboard/focus checks, a 360px screen, tablet landscape and desktop checks. Test long names/notes, missing optional values, large rupee amounts, decimal quantities, slow requests and repeated clicks where relevant. Device emulation does not replace a real-phone check before launch. No individual feature milestone means the system is ready for live trading; Release 1 launch requires M39-R1 after all R1 gates. Release 2 has its own M39-R2 acceptance.

## Decisions to resolve at the affected milestone

Resolve these in the existing source docs before implementing the affected behavior. Unrelated work may continue; do not invent business policy inside code.

| Decision/gap | Required by | What must be settled |
|---|---|---|
| Real shop product type and GST profile | M07/M10; final launch M39 | Units and categories, registrations, same-GSTIN branches, product taxability and input-tax recoverability. Batch/expiry/serial/variants are not silently added. |
| First owner/device bootstrap | M03–M04 — resolved | Approved local setup command creates first owner and a one-time pairing code. Later devices require owner approval. Local recovery requires owner credentials. No public registration. |
| MySQL constraints and circular references | M03 onward | Stage migrations and add later foreign keys when both tables exist; child-to-parent tenant invariants and exactly-one-target constraints cannot be left as comments. |
| Supplier receipt/return tax and cost policy | M10 | Supplier-document GST versus selling-registration behavior, recoverable versus nonrecoverable tax, component rounding. Confirm worked purchase examples. |
| Owner purchase correction valuation | M11 | RULES U requires a deterministic preview but does not fully specify how each consumed/remaining cost difference is allocated. Agree worked metadata/quantity/price/supplier/deletion examples; no retrospective COGS changes or silent unrelated-stock revaluation. |
| Historical/as-of reporting | M12/M16 | Opening evidence versus a new financial effect; allocation effective dates and signs. Every mutable input needed for recorded_as_of must have dated history, including allocations/status, rather than being inferred from today's row. |
| Sale cancellation after advance/opening-credit use | M21 | How applied credits are restored and refunds/retained credit are conserved, especially Walk-in. No invented payment. |
| Partial return component conservation | M20 | Independently rounding taxable/tax splits can break per-return sums. Agree residual allocation that preserves both each returned line's identity and cumulative original totals. |
| Finalized purchase status/permission wording | M11 | Explicit owner permission for revision/delete; posted/deleted statuses are authoritative, not legacy cancellation columns. Preserve alias behavior without creating two workflows. |

## Phase 0 — Foundation

### M01 — Local runtime and repeatable test environment

**Implementation order.** Inspect installed runtimes and lockfiles. Install the skeleton dependencies. Configure separate development and test databases. Verify the runtime test driver and database name before executing any reset, then boot the app and build its assets.

**Build:** Set up PHP 8.3+ compatible with Laravel 13, Composer, MySQL 8 and Node/build tooling. Follow `application/AGENTS.md` for prerequisite/Boost setup and reread generated guidance. Install base dependencies, configure local environment and a separate MySQL test database, generate local key, retain secrets outside Git. Verify required package compatibility before adding packages at their owning milestones. Establish browser-test availability without pretending a skill is MCP.

**Check:** Laravel boots, default tests run against the intended test database, MySQL connection is confirmed, frontend build succeeds, `.env` and dependencies are ignored. Document exact setup and executable test commands in README; a fresh checkout can repeat them.

**Demonstrate:** Start the default app, request its health page, run the baseline checks. **Boundary:** no POS models/features yet.

### M02 — Exact money, quantities and API conventions

**Implementation order.** Implement decimal parsing and range checks first. Implement rounding and quantity validation against the worked examples. Exercise the response envelope and validation errors on the normal API path.

**Build:** Shared decimal/money helper, two-place monetary strings/storage, three-place quantities, basis points, deterministic rounding and bounds. Versioned JSON success/error/pagination conventions, whitelisted sorting, UTC/Asia-Kolkata date helpers. No financial logic in UI.

**Check:** `0.125 × 12.35` rounds to `1.54`; reject excess monetary precision and overflow; unit whole-number validation; stable 422/409 shapes; pagination cap; local-day/FY boundary.

**Demonstrate:** Valid/invalid API requests and money tests. **Boundary:** no tax or stock posting yet.

### M03 — Tenant, branch and role boundaries

**Implementation order.** Seed the installation tenant and owner. Add branch membership and role policies. Exercise the same record lookup as owner, assigned cashier and an unrelated tenant.

**Build:** One tenant, initial branch/owner seeds, user/branch relations, Spatie permissions and branch resolver/policies. Establish tenant-safe uniqueness, foreign keys and first-owner bootstrap defined above. Seed extra tenants only as isolation test fixtures, not a SaaS feature.

**Check:** Owner is limited to its tenant; manager/cashier cross-branch requests denied; nested IDs cannot cross tenants; invalid/inactive branch rejected; seed rerun is safe.

**Demonstrate:** Owner access and cashier denial through API. **Boundary:** no public tenant/user self-registration.

### M04 — Registered devices and authentication

**Implementation order.** Resolve first-device enrollment and how a returning workstation proves enrollment before issuing a token. Persist the trusted binding, then implement session and token checks. Revoke an active device while its session is open and retry a protected action.

**Build:** Owner-approved device records/branch grants, secure session/CSRF auth, tenant/device-bound Sanctum tokens with finite expiry, logout, deactivation and revocation. Trusted channel derivation; complete first-device bootstrap. Browser tokens never go in localStorage.

**Check:** Unknown/revoked device denied; user/device/token tenant mismatch denied; effective branch intersection enforced; role changes apply immediately; expiry/revocation/logout; credential throttling and CSRF; token plaintext absent from logs.

**Demonstrate:** Register device, authenticate, revoke, show its old session/token no longer works. **Boundary:** no desktop/mobile app.

### M05 — Audit, idempotency and transaction primitives

**Implementation order.** Implement request hashing and uniqueness in MySQL. Wrap a test mutation, response and audit in one transaction. Force a failure and a simultaneous retry on two connections and inspect all committed rows.

**Build:** Append-only audit context, reason capture/redaction, tenant-bound idempotency storage, shared lock-order conventions and bounded transaction retries. Implement primitives with test-only fixtures rather than inventing a business document.

**Check:** Same key/payload returns original committed response; changed payload conflicts; rollback leaves no partial effects; concurrent duplicate requests commit once; secrets redacted; source audit cannot be removed through application actions.

**Demonstrate:** Duplicate and failed API submissions using focused fixture tests. **Boundary:** all following posting services use these primitives.

## Phase 1 — Users, branches and settings

### M06 — Administration API

**Implementation order.** Build branch and staff management before settings. Apply the permission matrix on every write. Exercise deactivation, manager delegation limits and all-branches filters with two branches.

**Build:** User lifecycle/password reset, branch assignment/deactivation, tenant settings/logo, GST-state references, cashier discount and negative-sales-stock settings. Manager can manage only eligible cashiers/branches; permission rules protect self-escalation.

**Check:** Manager cannot create owner/extend own scope; deactivation revokes access; branch code immutability after numbered history; allowed settings bounds/logo validation; GST change does not modify historical fixture snapshots.

**Demonstrate:** Create cashier, assign branch, change setting and verify access. **Boundary:** web administration comes in M29.

## Phase 2 — Catalogue

### M07 — Product master and price history

The confirmed launch profile is books, bookstore accessories and study accessories. General retail, electronics, clothing and optional per-product expiry tracking are parked. Use configurable catalogue references. Confirm actual GST registrations and tax treatment with the shop before enabling financial posting.

**Implementation order.** Build units and product identity before lookup and pricing. Add slabs and price history. Create branch balance rows at zero and verify a price edit causes zero stock movements.

**Build:** Products, categories, units, tax rates, SKU/barcode lookup, retail/wholesale prices, up to five wholesale slabs, reorder level, activation and price/slab/tax audit history. Create zero balance rows safely; opening stock form effects wait for M10's stock service, not direct balance edits.

**Check:** SKU/barcode uniqueness per tenant; decimal-unit rules; qty 9 versus 10 slab boundary; retail ignores wholesale slabs; history prevents destructive product deletion; catalogue changes preserve existing document fixtures; stock-row creation race.

**Demonstrate:** Add/search product, change price, inspect before/after history. **Boundary:** no stock changed by a product price edit.

### M08 — Product CSV import

**Implementation order.** Validate the whole CSV in dry run. Commit the validated set atomically. Keep a failing row in the middle of a sample file to prove no partial import.

**Build:** 2,000-row cap, decimal rupee validation, row errors, dry run and all-or-nothing import of master data. An opening_qty column must be rejected as not yet available until M10, then routed through current-date costed stock adjustment; never silently ignored or posted directly.

**Check:** Dry run writes nothing; one invalid row prevents commit; duplicate references and wrong decimal precision report row numbers; repeated imports do not silently duplicate opening stock; existing prices produce audit history.

**Demonstrate:** Import valid/invalid samples. **Boundary:** complete opening-stock import integration at M10.

## Phase 3 — Stock, suppliers and purchases

### M09 — Stock ledger and costing core

**Implementation order.** Implement one stock posting path that updates quantity, value and signed ledger together. Implement average cost and shortage estimates there. Rebuild totals from ledger rows and compare by branch and product.

**Build:** Append-only quantity/value ledger, locked stock projections, moving-average receipt/issue costing, depletion residuals, estimate provenance and negative-stock receipt settlement/variance. Internal service issue tests may use fixtures before the sales API exists. Implement stock list/history API and stock reconciliation command.

**Check:** Sum of ledger equals quantity/value balances; zero depletion clears value; shortage estimates never divide by negative stock; mixed available/shortage quantities; negative-to-positive receipt; no rewritten historic COGS; concurrent mutations and rollback.

**Demonstrate:** Fixture receipt/issue/reconcile with known and unknown costs. **Boundary:** only sales shortage paths can permit negative stock.

### M10 — Physical counts and existing-stock entry

**Implementation order.** Implement delta adjustments first, then physical counts computed under lock. Wire product opening stock and CSV to the same posting path. Exercise the rice example including stale-count rejection.

**Build:** Signed adjustments, current-date physical counts, mandatory explicit addition cost, stale-count detection, audit and correction-by-new-entry. Connect product creation/import opening stock to this service. Finalize purchase GST/cost examples needed by M13.

**Check:** Rice example `-3 → count 47 @ 40` posts +50, value 1,880, original estimated sales unchanged; known/unknown prior-cost variance; zero-qty cost correction; attempted stock backdating rejected; negative removal rejected; stale count conflict.

**Demonstrate:** Owner count plus a stale second submission. **Boundary:** no historical stock replay.

### M11 — Purchase correction design and revision primitives

**Implementation order.** Work through a cost-only revision after partial consumption with actual numbers. Confirm the allocation of value between remaining stock and variance. Build the preview and immutable revision structure against that agreed example.

**Build:** Resolve the correction valuation decision table first in source docs. Add immutable purchase revision/effect records, expected_revision handling and correction-preview contract, using fixtures until M13 creates purchases. Define supplier substitution, returned-quantity limits and paid-funds disposition explicitly.

**Check:** Worked correction examples reconcile before/after stock/value/debt; stale revision rejected; historical invoice/item identity retained; estimates/variances explicit; no unrelated stock silently revalued. Do not claim full correction API complete before M14.

**Demonstrate:** Deterministic preview examples and version persistence tests. **Boundary:** actual return is distinct from correcting a typo.

### M12 — Suppliers, dated opening dues and payment core

**Implementation order.** Implement supplier and branch accounts first, then opening entries and ordinary payments. Add evidence links and reversals. Compare effective-date and recorded-date views on the same backdated receipt.

**Build:** Supplier CRUD, branch accounts, opening-due/advance entries/status, dated supplier payments and allocations/reversals, historical evidence already included in opening balances, allocation effective dates. Generic tables may support future customers, but customer endpoints wait for phase 4.

**Check:** Not-entered differs from zero without blocking entry; pre-launch due/payment works; evidence is not counted twice; FIFO debt eligibility; advance applied later; reversal and consumed-credit dependencies; cross-party/branch denial; reproducible effective-date balances.

**Demonstrate:** Enter old supplier due, part-pay, add advance, reverse an eligible payment. **Boundary:** no fake old invoices/stock/GST for opening dues.

### M13 — Purchase posting API

**Implementation order.** Post an unpaid purchase first, including revision 1. Add the shared tax calculation and optional allocation/payment in the same transaction. Re-run with a duplicate supplier number and an injected failure after stock posting.

**Build:** Purchase + lines + supplier snapshots + revision 1, tax/cost calculation, receipt ledger and optional supplier payment atomically. Supplier invoice number stays external. Historical supplier bill date is allowed; stock receipt date remains now. Resolve circular FK ordering safely.

**Check:** Duplicate supplier invoice rejected, inclusive/exclusive purchase examples, recoverability cost basis, partial payment, price update audit, idempotent double submission and injected rollback. Purchase total, payable and stock reconcile.

**Demonstrate:** Post paid/unpaid purchase and inspect stock plus supplier ledger. **Boundary:** no internal tax-invoice number assigned to external supplier invoice.

### M14 — Owner edit/soft-delete purchase API

**Implementation order.** Preview from the current version. Lock and check that version again at commit. Append differential movements and immutable snapshots, then rebuild the active view. Force a stale edit after preview.

**Build:** Preview/commit revisions, metadata/quantity/cost/supplier corrections, owner-only soft deletion, history and active/deleted views, append-only allocation adjustments. Legacy cancel alias uses this one workflow. Later product movement alone never blocks correction.

**Check:** Manager/cashier denied; valid correction after later issue succeeds; invalid negative stock/returned quantity/consumed allocations block atomically; retained paid funds become advance unless actual refund recorded; every version retained; same retry changes balances once.

**Demonstrate:** Correct an old purchase, inspect full diff/history, soft-delete an eligible one and inspect retained history. **Boundary:** no physical historical deletion or old-sale recosting.

## Phase 4 — Customers, sales, payments and returns

### M15 — Customers and opening credit accounts

**Implementation order.** Create the protected walk-in and saved customer paths. Reuse the opening-account behavior for customers. Verify ledger visibility and branch-wide credit inputs separately.

**Build:** Customer CRUD/quick-create API, protected Walk-in, preferred pricing, credit limits, dated opening debt/credit and status using M12 primitives. Extend historical receipt/advance semantics to customers.

**Check:** Active phone uniqueness, no Walk-in credit/opening dues, old receipts/evidence conservation, overdue/opening balances across branches, append-only correction; missing opening never blocks ordinary entry.

**Demonstrate:** Enter an old customer due and collect part without creating an invoice. **Boundary:** incomplete recorded balances must be labelled.

### M16 — Customer settlement and reconciliation

**Implementation order.** Allocate a receipt against eligible opening debt, then exercise FIFO, unallocated advances, dated application and reversal. Verify invoice applications using isolated fixtures now and actual sales in M19.

**Build:** Receipts, split allocation, opening-credit application and unallocated advances, dated FIFO, reversals and ledger verification. Invoice targets may use fixtures until M19; keep actual settlement components usable without sales.

**Check:** Opening debt 1,000 minus receipt 400 equals 600; advance later applied without double credit; allocation date cannot predate source/target; evidence zero-effect reversal; recorded_as_of reproduces earlier state; no cross-branch application.

**Demonstrate:** Opening due, payment, advance and later application ledger with both dates. **Boundary:** never sum payment headers and their allocations twice.

### M17 — Pricing/GST preview and fingerprints

**Implementation order.** Extend the purchase arithmetic from M13 with sale pricing, discounts and supply context. Resolve prices on the server, emit a reproducible fingerprint, and verify the preview performs no financial writes.

**Build:** Shared sale calculator, retail/wholesale/slab selection, permissioned overrides, line/bill discounts with paise residuals, inclusive/exclusive tax, place-of-supply context, registration/document type resolution and canonical SHA-256 pricing snapshots. Expose calculate API; Livewire will reuse service.

**Check:** Two units at 118 inclusive/18% produce taxable 200, local CGST/SGST 18 each or interstate IGST 36; zero-tax scenarios; mixed price-inclusion flags; discount residuals/limits; stable hash changes only for relevant inputs; unsafe tax combinations rejected clearly.

**Demonstrate:** Calculate cart in retail/wholesale with and without discount. **Boundary:** preview creates no number, payment or stock movement.

### M18 — Complete document numbering

**Implementation order.** Build series administration and uniqueness constraints before allocation. Lock the owning series and allocate only inside the backing document transaction. Exercise exhaustion, rollback and the April boundary.

**Build:** Configurable series and registry, tenant/branch/FY/type/channel allocation, invoices, credit notes, purchase returns and transfers; trusted device/channel; disabled debit-note type. One-letter/five-digit format, X/Y/Z initial credit-note reservations, unused series allocation and rollover.

**Check:** At most 16 characters; 99999 rolls only within owning type/channel; missing/exhausted series blocks only its action; cancelled number retained; I/O rejected; year boundary/provisioning; concurrency produces unique serials. Tests use real/fixture backing documents within the same transaction, never orphan registry rows.

**Demonstrate:** Preview configuration plus controlled boundary tests; merely previewing consumes no number. **Boundary:** no fixed PR/CN/TR prefixes or future offline issuance.

### M19 — Atomic completed sale API

**Implementation order.** Orchestrate the shared pricing, stock, numbering and payment services inside one transaction. Inject a failure after each effect and race two terminals. Expose the completed invoice only after commit.

**Build:** Complete sale + stored line/identity/cost snapshots + number/registry + stock + split payments/credit + audit in one transaction. Lock and recheck price fingerprint, stock and aggregate customer credit limit. Use M16 allocations with real invoices now.

**Check:** Concurrent last-unit sale with negative stock off/on; overlapping credit limit check across branches; negative estimate provenance; preview/commit price race; fake client totals ignored; rollback after number/stock/payment steps; retries post once; Walk-in fully paid.

**Demonstrate:** Retail cash sale, wholesale partial-credit sale and negative-stock sale; inspect every affected ledger. **Boundary:** no UI/printing needed to prove atomicity.

### M20a — Retained held-bill lifecycle

**Implementation order.** Persist a hold with its snapshot and fingerprint. Lock that same row on completion and compare current pricing. Race resume against resume and verify only one finalized sale ID.

**Build:** Hold/resume/finalize/discard the same sales ID, preserving held cart/pricing snapshots and save/completion actors. Completion reuses M19 posting, recalculates current prices and requires the acknowledged fingerprint.

**Check:** Held state has no stock/payment/number/debt effect; competing completions post once; failed completion remains held; changed price requires acknowledgement; discarded hold remains stored; original ID/snapshots survive completion.

**Demonstrate:** Hold a pen at 10, change catalogue price to 20, resume, acknowledge and complete that same held sale. **Boundary:** never delete/recreate the held record or create a second sale.

### M20b — Invoice JSON and stored snapshots

**Implementation order.** Read invoice economics from stored document values. Add current settlement as a separate section. Compare JSON before and after a master-data change and a later collection.

**Build:** Authorized invoice JSON from persisted financial/identity/line snapshots, with separately labelled live settlement, original invoice references and optional note. Returns will reuse the contract after M20c.

**Check:** Editing product/customer/shop master data never changes issued economics; note edits follow owner/history rules; later collections update settlement only; all money serialized to two places; cross-branch/tenant denied.

**Demonstrate:** Save invoice JSON, change catalogue price/name and collect a balance; show unchanged economic snapshot and correctly updated settlement. **Boundary:** browser rendering waits for M33.

### M20c — Partial/full sales return and credit notes

**Implementation order.** Calculate the return against original item snapshots and cumulative returned quantity. Allocate the final paise residual, issue a credit note, then settle debt/refund and restore stock together.

**Build:** Original-price/tax/cost returns, cumulative component/residual conservation, retained original item links, credit-note numbering, stock restoration, debt reduction and actual refunds or retained credit. Resolve return-rounding decision before coding.

**Check:** Over-return and duplicate retry denied; several partial returns sum exactly to original taxable/tax/total/cost on full return; original estimated-cost provenance retained; credit/refund conservation and atomic registry/stock/payment rollback.

**Demonstrate:** Return one pen from an original invoice priced at 10 after catalogue price becomes 20; credit remains 10. Repeat partial returns to full quantity and reconcile. **Boundary:** no return priced from current catalogue.

### M21 — Sale cancellation, credit-note reversal and invoice comments

**Implementation order.** Implement sale cancellation, then eligible credit-note reversal with dependency checks. Exercise a paid and an unpaid document. Implement owner comment edits separately and prove all financial fields stay identical.

**Build:** Authorized sale cancellation/window, dependency-aware credit-note cancellation, actual refunds versus retained credit, original cost reversals and note-only owner revisions with history. Number retention and registry status update are atomic.

**Check:** Already-returned sale cancellation blocked; Walk-in cannot retain credit; applied opening/advance credits restored correctly; double cancel/reversal denied; actual refunds counted once; cancelled note effects unwound; comment edit changes no money/stock and retains old text.

**Demonstrate:** Cancel eligible sale, inspect refund/credit and retained number, edit a comment and inspect history. **Boundary:** no owner rewrite of finalized sale economics.

### M22 — Supplier return posting

**Implementation order.** Link the return to the current eligible purchase lines. Calculate remaining returnable quantity, supplier credit and carrying-value effect. Post once and verify supplier balance, inventory and issued number.

**Build:** Purchase returns linked to original lines, return-number registry, current carrying-stock issue, supplier credit versus actual refund, separate supplier acknowledgement reference. Recheck owner revisions/returned-quantity dependencies with real returns now.

**Check:** Qty cannot exceed corrected purchased qty minus returns; insufficient physical stock denied; refund/debt/variance conservation; duplicate retry; retained references; correct purchase_return series; no second debit-note posting for the same return.

**Demonstrate:** Return part of an unpaid purchase and part of a paid purchase. **Boundary:** supplier return means actual goods returned, never a typo correction.

## Phase 5 — Branch transfers

### M23 — Draft and dispatch

**Implementation order.** Validate both endpoints and available quantity on dispatch. Commit source issue, in-transit value and the transfer number together. A repeat dispatch must produce exactly one issue.

**Build:** Same-GSTIN source/destination transfer, draft edit/delete, source permission, dispatch stock/value to in-transit and number once from source branch. Retain dispatch/device provenance.

**Check:** No draft stock/number effect; source/destination differ; insufficient stock denied even if negative sales enabled; concurrent dispatch one result; registry/source FY/rollback; distinct-GSTIN rejected.

**Demonstrate:** Draft, change, dispatch and reconcile source plus in-transit value. **Boundary:** no post-dispatch deletion/cancellation action.

### M24 — Receipt and discrepancy resolution

**Implementation order.** Receive by original transfer ID at destination. Record the shortfall still in transit. Apply recovery and write-off independently and reconcile each item to dispatch.

**Build:** One final destination receipt, shortage still in transit, recovered/write-off resolutions and discrepancy view. No new number at receipt; original carried cost preserved.

**Check:** Dispatched = received + recovered + written off + unresolved; over-receive/over-resolution denied; authorized destination only; received + in-transit + loss value conserved; source never deducted twice; retries safe.

**Demonstrate:** Dispatch 10, receive 8, recover 1 and write off 1. **Boundary:** do not invent a physical ledger deduction for goods already dispatched.

## Phase 6 — Reports and dashboard

### M25 — Operational reports and dashboard API

**Implementation order.** Start with a hand-totalled trading day. Write per-report grouped queries over stored facts, keeping payments separate from allocations. Reconcile filtered results before caching the dashboard. Add transfer facts only in R2.

**Build:** R1: daily/product/cashier sales, stock valuation/low stock, customer/supplier outstanding, payments, provisional gross profit, top products and branch-cached dashboard. R2: extend with transfer/in-transit/discrepancy metrics after M23/M24. Separate estimated COGS/valuation variance and in-transit value. Include opening completeness warnings.

**Check:** Each report equals signed source sums after sales, returns, cancellations, revisions and payments; collections differ from sales; local day filters; tenant/user branch scope; cashier sees own daily summary only; cache key includes authorized context.

**Demonstrate:** Reconcile one seeded trading day by hand against report totals. **Boundary:** gross profit labelled as gross/provisional, not net business profit.

### M26 — GST, documents-issued and as-of reports

**Implementation order.** Build series counts from issued records, then implement date cut-offs and GST classifications. Check the same period before and after a later cancellation or purchase revision using dated history.

**Build:** GST sales/purchases exports' data endpoints, invoice/credit-note classification, issued-series first/last/total/cancelled/net/gaps, financial effective-date and recorded_as_of filters, purchase revision history at cut-off.

**Check:** Exclude reserved unused capacity; include cancelled once in total/net logic; internal supplier returns/transfers not outward GST sales; late entry changes effective-period report while prior recorded_as_of remains reproducible; zero and unknown opening differ.

**Demonstrate:** Series rollover/cancellation report and a late historical payment shown under two cut-offs. **Boundary:** no automatic GST filing or enabled debit notes.

### M27 — Excel exports and audit viewer API

**Implementation order.** Reuse the authorized report query for the workbook. Chunk rows, enforce the cap and inspect an exported file. Expose the general audit viewer under owner permission.

**Build:** On-demand maatwebsite/excel exports, chunking and 50,000-row cap, authorized audit filters/detail API. Neutralize spreadsheet formula input in user text. No background export service.

**Check:** Export matches filtered JSON totals/branch scope; cap/memory handling; amounts retain two decimal places without float loss; safe user text; no credentials in audit export; deleted purchase versions accessible only as permitted.

**Demonstrate:** Download one workbook and inspect records/totals plus audit detail. **Boundary:** no scheduled reports.

## Phase 7 — Livewire website, screen by screen

### M28 — Mockup alignment and shared web shell

**Implementation order.** Render the reference mockup and capture the overview, POS and list pages at desktop and narrow widths. Translate the shell into reusable Blade components. Compare the production render at the same widths and document intentional differences.

**Build:** Translate existing navy/blue/light sidebar/table layout into Blade + Livewire + daisyUI. Clear active branch, responsive navigation, reusable form/table/dialog/feedback states, keyboard focus, money/date formatting. No duplicate Alpine install. Select Livewire-compatible notifications rather than installing React/Sonner solely for toasts. Resolve a working browser-test path.

**Check:** Real rendered screenshots at 360px, tablet and desktop; keyboard menu/dialog behavior; contrast and text legibility; stable layout/zoom; no blank dead-end controls or fake sync claims; forged Livewire public properties fail authorization.

**Demonstrate:** Shell, branch navigation and validation feedback using existing service APIs internally. **Boundary:** no visual redesign or native Android app implied by narrow preview.

### M29 — Login and administration screens

**Implementation order.** Implement login and branch selection, then device/staff/settings and series administration. Use real policies on each Livewire action and test a forged branch ID directly.

**Build:** Login/device enrollment and revoke UI, branch/user/permission/settings forms and series configuration/exhaustion alerts. Reuse API policies/services directly, without same-server HTTP calls.

**Check:** Owner versus manager/cashier navigation and server denials; revoked browser blocked mid-session; login errors and field focus; no circular first-device setup; role scope changes visible; no reset of used series.

**Demonstrate:** Owner enrolls cashier workstation and cashier signs in with correct branch. **Boundary:** role selector in old mockup is not real authorization.

### M30 — Catalogue and stock screens

**Implementation order.** Connect catalogue search, product edit/history and CSV validation. Add adjustment/count previews with stale-state recovery. Verify keyboard entry and branch visibility using real saved data.

**Build:** Product search/form/import dry-run errors, price history, current branch stock/low-stock and ledger viewer, adjustment/count with before/after quantity/value/cost preview. Show estimated/negative stock clearly.

**Check:** Long SKU/name and Indian amounts; decimal unit entry; price-history values; stale count recovery without duplicate ledger; import invalid rows; current-date-only stock form; keyboard and narrow layout.

**Demonstrate:** Add product, import sample, change price, count stock and inspect ledger. **Boundary:** stock is never edited directly from a product table cell.

### M31 — Purchase entry, correction and history screens

**Implementation order.** Start with a real supplier bill entry grid and its details. Add owner correction preview, confirmation and version history. Add supplier-return controls only with the R2 posting service.

**Build:** R1: supplier picker, purchase grid/tax/payments, owner-only correction preview/confirmation, old versions/diffs and soft-deleted history. R2: supplier-return form after M22. Display impact on stock, valuation, dues and funds explicitly.

**Check:** Manager cannot access edit/delete; stale revision explanation; paid deletion does not silently refund; existing return conflict; history remains after refresh; duplicate-click prevention backed by idempotency.

**Demonstrate:** Owner corrects old purchase after later stock movement and inspects original version. **Boundary:** no fake supplier return for correction.

### M32 — POS cart and held bills

**Implementation order.** Start with barcode-to-cart focus and quantity editing. Add customer/pricing controls, discounts and live totals. Add hold/resume and changed-price acknowledgement after the normal cart flow works.

**Build:** Barcode/search focus, editable cart/quantities, customer quick-add, retail/wholesale/slabs, permissioned discounts/price overrides, visible GST/totals, comment entry, shortcuts, hold/resume/discard and repricing notice.

**Check:** F2/F4/F8/F9/Esc, scanner Enter increments correctly; stale calculator responses cannot overwrite newer cart; current pricing acknowledgement; unknown/negative stock warning; held ID/history preserved; navigation does not silently lose a bill.

**Demonstrate:** Keyboard-first retail/wholesale cart, hold it, change price, resume with notice. **Boundary:** payment/print completes in M33.

### M33 — Checkout and invoice printing

**Implementation order.** Complete split payment before initiating print. Render the same saved invoice JSON into A4 and thermal formats, then exercise printer failure and reprint with the comment checkbox toggled.

**Build:** Cash/UPI/card/bank split, change display, balance-on-credit, explicit payment validation, pay-once recovery, invoice detail, A4 pdfmake and 58/80mm print CSS. Include comment checkbox is per print/reprint, not saved visibility. Sale/credit-note cancellation and return UI use existing services.

**Check:** Walk-in underpayment denied; cash tender versus applied payment handled correctly; double click/retry/timeout never posts twice; print failure keeps completed sale and allows reprint; long/multiline comment included/omitted; two-decimal totals; CANCELLED label; partial returns use original price.

**Demonstrate:** Complete split-payment sale, preview A4/thermal, toggle comment, reprint without new invoice; test real target printer before launch. **Boundary:** desktop ESC/POS printing is future work, not browser HTML printing.

### M34 — Customer/supplier ledgers and gradual onboarding UI

**Implementation order.** Show a branch ledger with opening status and both dates. Add receipt/payment/advance actions and reversal details. Verify the amount due before and after entering evidence already included in an opening amount.

**Build:** Party forms/detail, dated opening dues/advances, not-entered versus confirmed-zero status, receive/pay/reverse, historical receipt evidence versus new effect, effective and entered dates, running balances and history.

**Check:** Start billing without completed openings; enter pre-launch due later; preview avoids double counting old receipt; credit application date; future date denied; owner historical permissions; ledger balance matches API after refresh.

**Demonstrate:** Live sale then old due and receipt entry, with correct current and historical balances. **Boundary:** do not require a shop-wide onboarding lock.

### M35 — Transfer, report and dashboard screens

**Implementation order.** Connect R1 basic reports and dashboard to scoped filters. Test each role and empty states. Add transfer, GST, export and general audit screens only as their R2 services pass.

**Build:** R1: operational report screens and role-specific dashboard from M25-R1. R2: dispatch/receive/discrepancy workflows, GST/series/as-of reports, Excel exports and general owner audit viewer after M22–M27 as applicable. Loading/empty/error states and accessible tables/filters for all screens.

**Check:** Active branch visible; source versus destination actions; report/export filters agree; estimates/in-transit/opening warnings present; owner totals never shown to cashier; report cut-off retained; no irrelevant expense/shift/offline controls.

**Demonstrate:** Resolve transfer shortage, filter daily/series reports, export and open linked history. **Boundary:** all browser flows for the current release exist before its hardening gates; mark R2 portions remaining after R1.

## Phase 8 — Hardening and launch

### M36 — Complete business and browser regression

**Implementation order.** Run one full trading day through the actual website and API. Compare end-of-day counts, dues, collections and stock against independent expected values. Repeat changed paths under concurrent access.

**Build:** Fill only missing coverage for integrated cases, finalize demo-data seeder, run critical Playwright/browser journeys and MySQL concurrency suites. Stress long data/empty/error/slow-network states; verify protected actions via direct API and Livewire calls.

**Check:** Purchase → sale → partial return → collection → correction → transfer → reports reconciles. Concurrent last unit, credit limit, number rollover, held completion and revision stale update; device revocation; print retry. Fresh install and upgrade migrations on disposable databases; no N+1 on lists.

**Demonstrate:** Owner, manager and cashier journeys with no mock-only toast actions. **Boundary:** browser test results require actual browser execution, not source inspection.

### M37 — Performance, backups and restore

**Implementation order.** Measure search and posting before tuning. Inspect slow queries and their execution plans. Verify improvements against the same fixture, then restore a scheduled backup into an isolated database and reconcile.

**Build:** Scheduled database backup, 14-day retention, secure nonpublic storage, restoration procedure, indexes/EXPLAIN, necessary rate limits and measured slow-query fixes. No mandatory queue service.

**Check:** Restore to separate database and reconcile; backups inaccessible from public URLs; schedule and retention verified; benchmark sale <300ms for 10 lines and search <200ms for 20,000 products on a specified basic VPS with stated conditions. Record actual results; do not claim shared-host timings from local results.

**Demonstrate:** Backup, isolated restore and report reconciliation. **Boundary:** no destructive restore against live business data during tests.

### M38 — Shared hosting/VPS deployment rehearsal

**Implementation order.** Rehearse the chosen host using built assets and application/public as the document root. Exercise login, sale, print and backup from a fresh session. Repeat with an upgrade from the prior release for R2.

**Build:** Update root README with commands run from application/, document root application/public, HTTPS/session setup, MySQL credentials, cron, permissions, asset build and operational rollback. Review production environment/debug/log settings and dependency compatibility.

**Check:** Deploy to a clean representative staging host; no .env/docs/vendor exposed through public routes; migrate and build; login/device registration, sale, print, export and backup work; server restart preserves state. Confirm host supports chosen PHP/MySQL and required extensions.

**Demonstrate:** Complete staging trade from a fresh browser and registered device. **Boundary:** publishing live or enabling real billing requires the actual authorized deployment step.

### M39 — Shop acceptance and release decision

**Implementation order.** Collect shop-user evidence for the current release on the intended scanner and printer. Record accountant review, restore result, unresolved issues and release decision. Mark the release ready only when its scoped checks pass.

**Build:** Execute acceptance with representative shop users and accountant review for actual GST profile, document formats, rounding, input tax and required external compliance workflows. Confirm permissions, training on purchase corrections/history and incomplete-opening labels. Finish real scanner/printer and physical-phone checks.

**Check:** All prior gates passed with recorded evidence; no blocking issue; reliable backup/restore and support procedure; owner can start with incomplete opening data and add records safely. Confirm every deferred legally relevant workflow has a suitable operating process; do not silently expand software scope.

**Demonstrate:** Owner signs off representative retail, wholesale, credit, purchase-correction, return and branch-transfer flows. **Boundary:** mark the current release ready only after its complete scoped gates; record actual release separately. R1 does not require R2 features, but R1 acceptance does require R1-scoped M36–M38.

## Mockup-to-requirement map

Reviewed sources: `mockups/interactive-website/index.html`, `app.js`, `style.css` and both mockup README files. They are illustrative source material, not proof of rendered usability or implemented flows. Previous browser walkthrough was blocked by preview access, so visual verification remains required in M28 onward.

| Mockup evidence | Planned treatment | Milestones |
|---|---|---|
| Navy/blue/light sidebar, cards and tables | Retain identity; improve legibility, real branch selection and accessible controls | M28–M30 |
| Owner/cashier/inventory-manager preview | Map to agreed Owner/Manager/Cashier roles, enforce server policies; no role impersonation switch | M03–M06, M29 |
| Overview identical across previews | Real branch/role-specific totals and permissions | M25, M35 |
| Product cards/fixed cart; add/checkout only toast | Real editable keyboard POS, customer/pricing/tax/payment | M32–M33 |
| Held button only | Retained held lifecycle and changed-price confirmation | M20, M32 |
| Customer/supplier summary tables | Opening status, dated dues, advances, receipts, allocation and history | M12, M15–M16, M34 |
| Purchase orders/receipts/returns tabs | Purchase bills/receipts and actual supplier returns; do not add purchase-order lifecycle by inference | M13–M14, M22, M31 |
| Missing owner purchase revision UI | Preview, reason, before/after diff, soft-deleted history | M11, M14, M31 |
| Missing invoice preview/comments | Thermal/A4, repeatable print-time comment choice | M20–M21, M33 |
| Static branch label, sample invoice IDs and integer amounts | Active branch, actual issued-series IDs and two-decimal amounts | M18, M28–M35 |
| Expenses/quotations/shifts/integrations/sync claims | Exclude from v1 unless scope is separately agreed; no “All changes synced” or offline sales claims | M28, M35 |
| Narrow Android-style toggle | Responsive website reference only; real-phone web QA, no native client | M28, M36, M39 |

## Requirement coverage

| Source area | Milestones |
|---|---|
| SPEC phases 0–1; RULES A/C/H/R | M01–M06 |
| SPEC catalogue; SCHEMA 3; RULES Q | M07–M10, M30 |
| SPEC purchases and owner correction; SCHEMA 5/15; RULES U | M11–M14, M22, M31 |
| SPEC gradual setup; SCHEMA 8/10; RULES E/P | M12, M15–M16, M34 |
| SPEC pricing, invoice JSON, held lifecycle; SCHEMA 4/13/16; RULES B/D/F/S | M17–M21, M32–M33 |
| SPEC complete number sources; SCHEMA 9/14; RULES T | M18, M22–M23, M26, M29 |
| SPEC transfers; SCHEMA 7; RULES I | M23–M24, M35 |
| SPEC reports/dashboard; RULES J | M25–M27, M35 |
| SPEC web UI/printing/comments; RULES G/O/R | M28–M35 |
| SPEC nonfunctional targets/deployment; RULES J/K | M36–M39 |

## Future work — not current milestones

After web/API v1, plan online desktop and mobile apps as separate deliverables using registered device tokens and invoice JSON. Desktop needs its own ESC/POS renderer, secure local token storage and printer retry/reprint behavior. Select desktop/mobile platforms before using native-specific skills. Write Swift applies only if an Apple native client is chosen.

Only after online clients are validated, revisit offline synchronization separately: local storage, secure device identity, exclusive series leases, durable cancelled numbers, sync deduplication, conflicts, clock/FY boundaries and lost-device incidents. Current device/series provenance is preparation, not implemented offline support. Debit notes, SaaS, ecommerce, full accounting, expenses/shifts, coupons, scheduled prices, backdated stock and special GST cases remain outside the approved v1 scope.

## Progress ledger

For each row record Not started / In progress / Passed / Needs decision, test evidence, demo and outstanding issue. Add R1/R2 slice status and evidence in the last column for split milestones; a passed slice is not a passed whole milestone. M20a, M20b and M20c are separate sequential completion gates. Keep the plan here; as requested, write numbered completion remarks and test evidence under task_remark/Mxx and update its task map after every completed task.

| Milestone | Status | Checks / demo / remaining issue |
|---|---|---|
| M01 | Passed | PHP 8.5.12, Composer 2.11.1, Laravel 13.40, isolated MySQL 8.4.9; assets, health and Chrome starter-page checks passed. [task001](../task_remark/M01/task001.md). |
| M02 | Passed | Exact decimals, bounds, pagination and India/FY boundaries verified. [task002](../task_remark/M02/task002.md). |
| M03 | Passed | Repeatable seeds, tenant/branch restrictions and local first-owner command verified. [task003](../task_remark/M03/task003.md). |
| M04 | Passed | One-time pairing, bound session/token, expiry, revocation, current permissions, real CSRF and throttling verified. Combined suite: 18 tests / 95 assertions passed on MySQL. [task004](../task_remark/M04/task004.md). Native clients and production login screens remain later work. |
| M05 | Passed | MySQL checks cover replay, conflict, rollback, redaction and overlap across two connections. [task005](../task_remark/M05/task005.md). |
| M06 | Passed | Branch and staff permissions, revocation, settings and numbered-history fixtures passed on MySQL. [task006](../task_remark/M06/task006.md). |
| M07 | Passed | Product lookup, price history, wholesale boundaries, stock-history protection and overlapping balance initialization verified. [task007](../task_remark/M07/task007.md). |
| M08 | Passed | CSV preflight, row errors, atomic commit and audited price updates verified on MySQL. [task008](../task_remark/M08/task008.md). |
| M09 | Passed | Stock projection, shortage valuation, rollback, reconciliation, scoped reads and concurrent last-unit sale verified. [task009](../task_remark/M09/task009.md). |
| M10 | Passed | Physical counts, stale versions, cost-only corrections, and atomic opening stock verified. Full MySQL suite passed 29 tests. [task010](../task_remark/M10/task010.md). |
| M11 | Passed | Confirmed remaining-unit policy, deterministic preview and immutable revision fixtures verified. [task011](../task_remark/M11/task011.md). |
| M12 | Passed | Dated supplier dues, payment evidence, FIFO, advance application, reversals and effective/recorded cutoffs passed on MySQL. [task012](../task_remark/M12/task012.md). |
| M13 | Passed | Purchase posting, stock receipts, supplier allocations and rollback verified. Full MySQL suite passed 34 tests with 381 assertions. [task013](../task_remark/M13/task013.md). |
| M14 | Passed | Owner preview/commit, soft deletion, immutable history, stock/payment conservation and rollback verified. Full MySQL suite: 44 tests, 531 assertions. [task014](../task_remark/M14/task014.md), [manual tests](../task_remark/M14/manual-tests.md). |
| M15 | Passed | Customer records, protected Walk-in, dated opening balances, historical receipts, and branch-scoped balances verified. Full MySQL suite: 45 tests, 572 assertions. [task015](../task_remark/M15/task015.md). |
| M16 | Passed | Customer receipt splits, dated advance and opening credit applications, reversals, and ledger reconciliation verified. Full MySQL suite: 46 tests, 632 assertions. [task016](../task_remark/M16/task016.md). |
| M17 | Passed | Server-side retail/wholesale and GST preview, discount allocation, fingerprints, and purchase regressions verified. Full MySQL suite: 47 tests, 678 assertions. [task017](../task_remark/M17/task017.md). |
| M18 | Passed | Series provisioning, rollover, trusted channels and unique invoice numbering verified. [task018](../task_remark/M18/task018.md). |
| M19 | Passed | Completed sales, settlement, rollback and concurrent stock and credit checks verified. Full MySQL suite: 48 tests, 792 assertions. [task019](../task_remark/M19/task019.md). |
| M20a | Passed | Focused MySQL sales regression passed (141 assertions). See task020. |
| M20b | Passed | Focused MySQL sales regression passed (149 assertions). See task021. |
| M20c | Passed | Focused MySQL sales regression passed (175 assertions). See task022. |
| M21 | Passed | Full MySQL suite passed (48 tests, 887 assertions). Final focused sales regression passed (228 assertions). See task023. |
| M22 | Not started | |
| M23 | Not started | |
| M24 | Not started | |
| M25 | In progress | R1 passed. Focused MySQL checks passed (2 tests, 293 assertions). [Task 024](../task_remark/M25/task024.md), [tests](../task_remark/M25/test-results.md). R2 transfer reporting is pending. |
| M26 | Not started | |
| M27 | Not started | |
| M28 | Passed | Paired workspace, branch switch, responsive overview and stock. MySQL regression passed (14 tests, 93 assertions). Chrome checks passed at 360, 768 and 1440 px. [Task 025](../task_remark/M28/task025.md), [tests and screenshots](../task_remark/M28/test-results.md). |
| M29 | Passed | Browser sign-in and scoped administration. Focused MySQL regression passed (18 tests, 177 assertions); Chrome sign-in verified on desktop and phone. [Task 026](../task_remark/M29/task026.md), [tests](../task_remark/M29/test-results.md). |
| M30 | Passed | Catalogue, CSV and stock movement screens verified. MySQL regression passed (6 tests, 173 assertions); Chrome verified keyboard search and phone layout. [Task 027](../task_remark/M30/task027.md), [tests and screenshots](../task_remark/M30/test-results.md). |
| M31 | Not started | |
| M32 | Not started | |
| M33 | Not started | |
| M34 | Not started | |
| M35 | Not started | |
| M36 | Not started | |
| M37 | Not started | |
| M38 | Not started | |
| M39 | Not started | |
