# M06 test evidence

4 November 2026. The dedicated MySQL test database passed the full suite with 23 tests and 148 assertions. After the final password-length and branch-reseeding changes, the focused `AdministrationTest|BranchAccessTest` run passed 4 tests with 41 assertions. Pint passed on the final changes.

The tests covered owner branch creation, manager limits across two branches, staff creation, token revocation after reset or deactivation, branch deactivation, default-branch reseeding, settings bounds, invalid GSTIN/state combinations, and upload and retrieval of a valid logo. The registry fixture verified that numbering locks the branch code and that changing shop GST registration preserves the existing snapshot.

The local development database applied the pending M05 and M06 migrations successfully. The API route listing returned 19 routes.
