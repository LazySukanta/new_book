# task018 — Document numbering

Owners can list and provision series for the selected authorized branch. Each allocation has a financial year, document type, channel, prefix, and rollover order. The request's prefix order determines rollover. Unused prefixes can be appended. Counters start at 1 and cannot be edited through the API. Prefixes I and O are unavailable.

The allocator derives the issue year in Asia/Kolkata and takes the channel from the authenticated device. It locks the branch and selected series. A backing document is written inside the same transaction as its registry entry and counter update. Numbers use one letter and five digits. After serial 99999, rollover stays in the same branch, year, document type and channel. The registry enforces number, source and series-serial uniqueness.

The invoice types are connected to completed sales. Credit-note, purchase-return and transfer series can be configured now. Their backing posting workflows arrive in M20c, M22 and M23. Debit notes remain unavailable. The registry has cancellation fields and preserves issued numbers. Actual sale cancellation and credit-note reversal arrive in M21.

## Provision a development series

Use owner authentication and `X-Branch-Id`. Send this body to `POST /api/v1/document-series`, replacing the year and type with those of your intended sale.

```json
{
  "financial_year": "26-27",
  "document_type": "commercial_bill",
  "owning_channel": "web",
  "prefixes": ["A", "B"]
}
```

Choose unused letters in that branch and year. The pricing preview returns the document type. A credit-note provisioning request can omit `prefixes` to provision X, Y and Z in that order, or explicitly choose unused letters. `GET /api/v1/document-series` lists configuration without issuing anything.

Run `php artisan test --filter=CompletedSaleTest` to exercise rollover, trusted channel selection, missing or exhausted series, and the April boundary in the dedicated MySQL test database. The test reaches the final serial using a test fixture. There is no production counter override.
