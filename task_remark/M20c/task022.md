# Task 022 - Sales returns and credit notes

Partial returns allocate each stored taxable, tax, discount and cost component by the cumulative returned quantity. Each posting receives the rounded cumulative amount less amounts on prior active notes. The line payable is the sum of allocated taxable and tax components. The current pricing policy uses zero invoice round-off.

Return posting links the original lines, updates stock and debt, and issues the numbered credit note with any actual refund in one transaction. Customer balances include issued credit notes and actual refunds.

The focused MySQL sales test passed with 175 assertions. Three one-unit returns after a catalogue price change each credited the original 10.00 and reconciled to 30.00. Replays reused their note IDs and an excess return was rejected.

Next is M21, cancellation, credit-note reversal and comment revisions.
