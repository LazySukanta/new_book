# Task 021 - Invoice JSON and stored snapshots

The authorized invoice route uses stored sale economics, party and item identities, and the saved note. Live settlement includes allocated payments, paid amount and balance.

The focused MySQL regression passed with 149 assertions. It compares issued snapshots across product, customer and shop edits, checks branch scope, and verifies later collections affect settlement separately.

Next is M20c. Owner note revision is scheduled for M21.
