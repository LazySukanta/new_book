# task006 — Administration API

Added authenticated administration routes under `/api/v1`. Owners manage branches and settings. Owners can create managers and cashiers. Managers can manage cashiers whose assignments are within their own branches. Password resets and staff deactivation remove existing sessions and bearer tokens. The staff API protects the bootstrap owner from staff edits.

The API exposes `branches`, `users`, `settings`, `settings/logo` and `gst-states`. Use `PATCH /users/{id}/active` with `is_active`, `POST /users/{id}/reset-password` with `password`, and `PATCH /branches/{id}/active` with `is_active`. Use multipart `POST /settings` with `logo` for a PNG or JPEG up to 1 MB and 2,000 pixels per side. Logo reads use the authenticated tenant's current settings.

Branch code changes lock the branch and check the issued-document registry when present. The code-change and tax-snapshot checks use MySQL fixtures for the future registry. Replace the fixture with real registry rows when numbering lands. Branch balance initialization belongs with the stock tables. Owners can configure a cancellation window up to 365 days and a receipt width of 58 or 80 mm.

The migration adds domestic GST state codes. [GST Portal state-code reference](https://tutorial.gst.gov.in/downloads/news/monthly_gst_data_for_mar_2026_for_publishing_final.pdf)

See [test results](test-results.md).
