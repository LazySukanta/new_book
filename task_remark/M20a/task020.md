# Task 020 - Retained held-bill lifecycle

The sales API saves, lists, updates, finalizes and logically discards held bills. Finalization uses the existing sale row and M19 posting transaction. Saved cart and pricing snapshots remain on the record with save, completion and discard actors.

The focused MySQL sales test passed with 141 assertions. It covers draft isolation, stale pricing, rollback on insufficient stock, idempotent replay, two-terminal completion and retained discard. PHP syntax checks passed.

Next is M20b, invoice JSON from stored snapshots.
