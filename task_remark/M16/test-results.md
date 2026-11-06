# M16 verification

- Full dedicated MySQL suite: **46 passed, 632 assertions**.
- Focused customer and supplier suite before the final permission assertion: **3 passed, 141 assertions**.
- Verified dated FIFO, split receipt rollback and retry, cross-branch rejection, historical application date limits, and unchanged net balance when applying unused receipts or opening credit.
- Verified that later applications block receipt reversal, receipt reversal restores the balance once, evidence reversal has zero effect, and a recorded-date cutoff reproduces the earlier balance.
- Verified owner authorization for historical receipt reversal. Pint passed, the development migration applied, and Laravel listed 17 customer routes.
- Settlement targets in these checks are opening dues. Customer sale invoice integration belongs to M19.
