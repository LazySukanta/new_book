# task011 — Purchase correction preview and revision history

Implemented the owner-confirmed remaining-unit rule in RULES §X. A historical correction updates only verified remaining inventory value and accounts for the difference as current variance. An update to future purchase costs leaves prior documents and stock movements intact.

Added preview calculations and append-only revision storage with stale-version checks and owner authorization. Supplier substitution previews preserve payment ownership and block incompatible returns. The service uses fixture purchase IDs until M13 provides purchase tables. M14 connects preview and revision writes to real purchase, stock and allocation effects.

See [test evidence](test-results.md).
