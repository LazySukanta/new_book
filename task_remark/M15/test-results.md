# M15 verification

- Full dedicated MySQL suite: **45 passed, 572 assertions**.
- Customer workflow: **1 passed, 41 assertions**. A ₹300 dated opening due, a ₹100 additional historical receipt, and a ₹50 receipt already included in the opening produced a ₹200 balance. A second branch had ₹50 outstanding, bringing the total to ₹250.
- Verified that walk-in credit and opening entries are rejected, normalized duplicate active phones are rejected, and cashiers can quick-create a default customer but cannot set a credit limit or post historical entries.
- Verified effective-date cutoffs, cross-branch evidence rejection, append-only opening reversal, a confirmed-zero opening, and no stock movement.
- Shared supplier account and test database checks: **2 passed, 43 assertions**.
- Pint passed on the changed PHP files. The local development migration applied successfully, and the customer route listing showed 11 endpoints.

The API reports dated outstanding amounts. Overdue classification has no due-date policy in the current requirements. The browser customer screens and sale-time credit-limit enforcement are later milestones.
