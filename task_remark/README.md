# Task and evidence map

Updated 6 November 2026. Source plan: [MILESTONES](../docs/MILESTONES.md).

| Task | Milestone | Scope | Status | Remark and evidence |
|---|---|---|---|---|
| task001 | M01 | Runtime and isolated MySQL | Complete | [Remark](M01/task001.md), [tests](M01/test-results.md) |
| task002 | M02 | Exact decimals and API conventions | Complete | [Remark](M02/task002.md), [tests](M02/test-results.md) |
| task003 | M03 | Tenant, branch, roles, first owner | Complete | [Remark](M03/task003.md), [tests](M03/test-results.md) |
| task004 | M04 | Approved devices and authentication | Complete | [Remark](M04/task004.md), [tests](M04/test-results.md), [output](M04/test-output.txt) |
| task005 | M05 | Audit, idempotency and transaction primitives | Complete | [Remark](M05/task005.md), [tests](M05/test-results.md) |
| task006 | M06 | Branch, staff and shop administration API | Complete | [Remark](M06/task006.md), [tests](M06/test-results.md) |
| task007 | M07 | Product master and price history | Complete | [Remark](M07/task007.md), [tests](M07/test-results.md) |
| task008 | M08 | Product CSV import | Complete | [Remark](M08/task008.md), [tests](M08/test-results.md) |
| task009 | M09 | Stock ledger and costing | Complete | [Remark](M09/task009.md), [tests](M09/test-results.md) |
| task010 | M10 | Physical counts and opening stock | Complete | [Remark](M10/task010.md), [tests](M10/test-results.md) |
| task011 | M11 | Purchase correction preview and revision history | Complete | [Remark](M11/task011.md), [tests](M11/test-results.md) |
| task012 | M12 | Suppliers, dated opening dues and payment core | Complete | [Remark](M12/task012.md), [tests](M12/test-results.md) |
| task013 | M13 | Purchase posting API | Complete | [Remark](M13/task013.md), [tests](M13/test-results.md) |
| task014 | M14 | Owner purchase correction and soft deletion | Complete | [Remark](M14/task014.md), [tests](M14/test-results.md), [manual guide](M14/manual-tests.md) |
| task015 | M15 | Customers and opening credit accounts | Complete | [Remark](M15/task015.md), [tests](M15/test-results.md) |
| task016 | M16 | Customer settlement and reconciliation | Complete | [Remark](M16/task016.md), [tests](M16/test-results.md) |
| task017 | M17 | Pricing and GST preview | Complete | [Remark](M17/task017.md), [tests](M17/test-results.md) |
| task018 | M18 | Document numbering | Complete | [Remark](M18/task018.md), [tests](M18/test-results.md) |
| task019 | M19 | Completed sale posting | Complete | [Remark](M19/task019.md), [tests](M19/test-results.md) |
| task020 | M20a | Retained held-bill lifecycle | Complete | [Remark](M20a/task020.md), [tests](M20a/test-results.md) |
| task021 | M20b | Invoice JSON and stored snapshots | Complete | [Remark](M20b/task021.md), [tests](M20b/test-results.md) |
| task022 | M20c | Partial/full returns and credit notes | Complete | [Remark](M20c/task022.md), [tests](M20c/test-results.md) |
| task023 | M21 | Sale cancellation, credit-note reversal and invoice comments | Complete | [Remark](M21/task023.md), [tests](M21/test-results.md) |
| task024 | M25-R1 | Basic operational reports and dashboard API | Complete | [Remark](M25/task024.md), [tests](M25/test-results.md) |
| task025 | M28 | Shared web shell and mockup alignment | Complete | [Remark](M28/task025.md), [tests and screenshots](M28/test-results.md) |
| task026 | M29 | Browser sign-in and administration | Complete | [Remark](M29/task026.md), [tests](M29/test-results.md) |
| task027 | M30 | Catalogue and stock screens | Complete | [Remark](M30/task027.md), [tests and screenshots](M30/test-results.md) |
| task028 | M31-R1 | Purchase entry, correction and history screens | Complete | [Remark](M31/task028.md), [tests and screenshots](M31/test-results.md) |

Use globally increasing task numbers. On completion, write scope, changed files, actual checks, limitations and next step in the milestone folder. Keep test evidence alongside remarks. Never store credentials or tokens. Passing partial checks do not complete a milestone. Commit each completed milestone or release slice after recording its verification.

Work through the release plan one milestone at a time. Start the next eligible milestone automatically once the current milestone is complete and its required tests pass.

Completed [M18 — Document numbering](M18/task018.md) and [M19 — Completed sale posting](M19/task019.md). The full MySQL suite passed 48 tests with 792 assertions. [M20a](M20a/task020.md) passed its focused MySQL regression. [M20b](M20b/task021.md) passed its focused invoice regression. [M20c](M20c/task022.md) passed its focused return regression. [M21](M21/task023.md) passed the full MySQL suite and final focused sales regression.
