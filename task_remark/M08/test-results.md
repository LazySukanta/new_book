# M08 test evidence

4 November 2026. On the dedicated MySQL database, the initial import and catalogue regression run passed 2 tests with 66 assertions. After the UTF-8 and collation changes, the import test passed with 35 assertions and Pint passed.

Verified dry-run isolation, atomic rejection of an invalid middle row, duplicate SKU and barcode row errors, accent-equivalent SKUs, decimal precision, 2,000-row limit, opening-quantity rejection, existing-SKU updates, price audit and zero opening balances.
