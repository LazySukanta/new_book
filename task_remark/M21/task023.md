# Task 023 - Sale cancellation, credit-note reversal and invoice comments

Sale cancellation preserves the issued number, reverses the original stock issue, detaches applied opening credit and receipts, and removes invoice debt. Original sale receipts can be refunded or retained as customer credit. Later allocated receipts become available advances. Walk-in refunds require an actual payment mode.

Credit-note reversal restores the debt and stock effects and records recovery of any refunded amount. Reversal requires available stock and value, and later active notes on the sale must be reversed first to preserve cumulative return allocations. The registry retains the cancelled number.

Owner comment edits update the presentation note and audit its previous value, new value, actor and reason. Stored invoice economics stay identical.

The full MySQL suite passed 48 tests with 887 assertions. The final focused sales regression passed 228 assertions, including rollback on credit-note issuance failure, one-paisa conservation across partial returns, paid and unpaid cancellations, refund recovery, debt restoration, comment history and stock reconciliation.
