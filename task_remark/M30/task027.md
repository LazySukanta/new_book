# Task 027 - M30 catalogue and stock screens

Open Catalogue from the paired workspace. Staff can search by name, SKU or barcode. Staff with catalogue and price permissions can add and edit products, wholesale quantity tiers and view retail, wholesale, cost, GST and tier history. Catalogue setup creates units, categories and GST rates through the existing role rules.

CSV validation displays row numbers and leaves products unchanged. Select the same file again to import it. Opening stock follows the branch shown on the form. If that branch changes before submission, the page asks for a fresh selection.

Stock tools include low stock, a filtered ledger and a separate count or adjustment form. The preview uses the posting calculation for before and after quantity, value and average cost. Its encrypted confirmation is scoped to the actor, branch and reviewed ledger position. Stock changes during the review require a new preview. A replay of the same confirmation does not create another movement. All postings use the current date.

The Chrome walkthrough used disposable products in the dedicated test database. See [test results and screenshots](test-results.md). Next is M31-R1, purchase entry, correction and history screens.
