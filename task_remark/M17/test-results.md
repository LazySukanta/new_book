# M17 verification

- Full MySQL regression suite: **47 passed, 678 assertions**.
- Pint passed on all changed PHP files. The development application lists `POST /api/v1/sales/calculate`. No migration was needed.
- Verified ₹236 inclusive at 18% with ₹200 taxable and either ₹18 CGST plus ₹18 SGST or ₹36 IGST.
- Verified highest eligible wholesale slab, permissioned price and place-of-supply overrides, inclusive and exclusive lines, one-paise discount allocation, discount limits, and supported zero-tax paths.
- Verified stable fingerprints for equivalent inputs and unrelated catalogue changes, a changed fingerprint after a retail price update, and no stock or payment writes.
- The first focused run passed the purchase tests and exposed a missing required shop name in the preview fixture. The fixture was corrected before the full passing run.

The tests verify preview fingerprints. Checkout comparison and persistent audit records for sale overrides are part of M19.
