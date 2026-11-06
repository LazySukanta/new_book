# M29 test results

**MySQL regression passed:** 18 tests, 177 assertions.

The browser-form test signs an owner in, renders all five administration areas, creates a cashier, approves the cashier browser, reads the one-time code and verifies it disappears after use of the approval page. The cashier signs in with that code and sees the authorized branch. The test rejects cashier administration requests, an unpaired branch submitted to series setup and a forged series counter. A revoked browser loses workspace access and can return to sign-in.

The production asset build passed. Chrome rendered the live local sign-in at 1440 px and 360 px. A failed sign-in focused the email field and cleared the password and pairing code.

- [Desktop sign-in](screenshots/sign-in-desktop.png)
- [Phone sign-in error](screenshots/sign-in-error-phone.png)

Run from the application directory with the configured MySQL test database available.

```powershell
npm run build
php artisan test --compact '--filter=DeviceAuthenticationTest|AdministrationTest|WorkspaceTest|BranchAccessTest'
```
