# M34 · Customer and supplier ledgers

Customers and Suppliers open branch-scoped directories, party records and running ledgers. Entries show both effective and recorded times in IST. Owners can record dated openings, distinguish confirmed zero from a missing opening, and reverse or replace opening records. Trading remains available while opening records are incomplete.

Staff with the relevant permission can record receipts and payments, allocate an available amount to an opening due, sale or purchase, and inspect or reverse allocations. Owner-entered historical evidence linked to an opening entry previews a zero balance effect. A historical payment with a new balance effect requires review and confirmation. The preview flags an existing posted payment reference.

## Verification

- Vite production build and Blade compilation passed.
- The focused MySQL regression passed with 7 tests and 243 assertions. Coverage included both workspace routes, branch checks, role denials, confirmed zero, historical evidence with zero balance effect, a retained reversal, and rejection of a future opening.
- Chrome completed a ₹10.00 credit sale with the opening not entered. An older ₹100.00 opening due brought the current balance to ₹110.00. The preview for ₹25.00 of receipt evidence showed zero change, and the posted evidence left the balance at ₹110.00. An effective-date cut-off before the sale showed ₹100.00.
- The supplier walkthrough confirmed zero opening, recorded a ₹20.00 advance, and reversed it to return the balance to ₹0.00. At 360 px, the ledger showed both dates, the entry effect and the running balance without horizontal scrolling.
- [Customer ledger](screenshots/customer-ledger.png), [historical evidence preview](screenshots/evidence-preview.png), and [supplier ledger on phone](screenshots/supplier-phone.png).

Run `npm run build` and `php artisan test --compact '--filter=CustomerOpeningTest|CustomerSettlementTest|SupplierAccountTest|WorkspaceTest'` from the application directory with the dedicated test database available.
