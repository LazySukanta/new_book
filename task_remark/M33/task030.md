# M33 · Checkout and invoice printing

F9 opens checkout from the POS. Staff can split cash, UPI, card and bank payments and leave an eligible customer balance on credit. Cash tendered and change are displayed separately from the amount applied to the invoice. The browser retains the original request and idempotency key for payment recovery after an uncertain response, including across a page reload.

Invoice history opens the stored invoice or credit note for the active branch. Download A4 PDF generates a PDF in the browser. Thermal printing uses the shop's 58 mm or 80 mm receipt width and the browser print dialog. The comment checkbox applies to the current print or download. Owners can separately edit a comment with an audited reason. Invoice totals come from the saved invoice, with the current settlement displayed separately.

The invoice page supports partial returns at original invoice pricing and authorized sale or credit-note cancellation. Cancelled invoices and credit notes carry a visible CANCELLED label.

## Verification

- Vite production build and Blade compilation passed. The browser loads pdfmake and its fonts when the user requests an A4 PDF.
- Payment arithmetic checked walk-in underpayment, credit balance, split payment, tender versus applied cash, non-cash overpayment and the full supported currency precision.
- Focused MySQL regression passed with 5 tests and 393 assertions on MySQL 8.4.13. Tests covered the paired web checkout, exact replay of a split payment, invoice and credit note rendering, a partial return at the original price after a catalogue change, and credit-note cancellation.
- Chrome verified that a ₹20.00 walk-in bill rejects ₹5.00 UPI alone. With ₹20.00 cash tendered as well, checkout applies ₹15.00 cash and ₹5.00 UPI and shows ₹5.00 change. After an intentionally dropped response and page reload, recovery opened the issued invoice and invoice history contained exactly one new document.
- Chrome downloaded an A4 PDF with and without the comment, showed a simulated print-launch failure, and retried printing the same invoice. It also issued a partial-return credit note and cancelled it with the recovered refund confirmed. See the [split-payment checkout](screenshots/split-payment.png) and [thermal print preview](screenshots/thermal-preview.png).
- The physical target printer check remains part of shop acceptance before launch.

Run `npm run build` and `php artisan test --compact '--filter=CompletedSaleTest|SalePricingTest|WorkspaceTest'` from the application directory with the dedicated test database available.
