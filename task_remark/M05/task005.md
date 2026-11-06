# task005 — Posting transaction foundations

Completed 4 November 2026. Added PostingTransaction, PostingAudit, the idempotency migration and MySQL feature fixtures. Posting callers pass an authorized branch and a validated payload containing target IDs. The transaction reserves the key before acquiring business locks, and commits the response with the posting and audit writes. It allows up to three transaction attempts for retryable database failures.

The scope is tenant, user, branch, operation and key. The hash sorts map keys while preserving list order. Replays restore the original JSON and status. Audit writes accept explicit snapshots, remove credential fields recursively and record the actor, device, branch and reason. Trading endpoints will use these primitives as their milestones are implemented.

See [test results](test-results.md). M06 is next.
