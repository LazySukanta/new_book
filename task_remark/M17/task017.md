# task017 — Pricing and GST preview

`POST /api/v1/sales/calculate` resolves an active customer or Walk-in, authorized branch, products, quantities, prices, discounts, GST and document type. It returns the resolved cart, totals and a SHA-256 pricing fingerprint. The service can be called from the future checkout flow. It creates no invoice, stock movement, payment, or audit entry.

Retail uses the retail price. Wholesale selects the highest eligible quantity slab or the base wholesale price. The customer preference supplies the default sale type. Price and place-of-supply overrides require their respective permissions. Cashier discounts are capped on the combined line and bill discount. Paise allocation and inclusive/exclusive tax calculations are shared with purchases.

The configured branch state determines the default place of supply. A customer GSTIN alone cannot change it. For the supported registration profiles, the preview resolves tax invoices, bills of supply, or commercial bills. The regular-registration path rejects mixed taxable and zero-rate products. The composition path rejects interstate previews. The branch needs its state configured and, for registered profiles, a matching GSTIN.

The fingerprint covers resolved pricing and tax inputs and results in cart line order. Equivalent decimal input spellings produce the same fingerprint. An unused wholesale price change does not affect a retail preview. A changed applied price does. Checkout fingerprint comparison and actual override auditing belong to M19.

## Test it

Run `php artisan test --filter=SalePricingTest` from the application directory with MySQL running. The test uses its own products and shop settings in the dedicated test database.

To preview a disposable development cart, send this body to `POST /api/v1/sales/calculate` with your authenticated token and `X-Branch-Id`. Replace product ID `12` with an active product ID.

```json
{
  "sale_type": "retail",
  "items": [{"product_id": 12, "qty": "2"}]
}
```

For the test fixture of ₹118 inclusive and 18% GST, the local preview returns ₹200 taxable, ₹18 CGST, ₹18 SGST, and ₹236 total. With an authorized interstate place-of-supply override, the preview returns ₹36 IGST and the same total. Change `sale_type` to `wholesale` to test the configured slab. Try a bill discount by adding `"bill_discount": {"type": "amount", "value": "0.01"}`.

The configured GST profile for real billing still needs the existing release acceptance. The example above is an arithmetic fixture.

Next is **M18 — Complete document numbering**.
