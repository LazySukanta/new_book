# M28 test results

## MySQL feature checks

**Passed.** The workspace, device authentication and branch access regression passed 14 tests with 93 assertions. It exercised the real Livewire update endpoint with session middleware enabled. An unpaired or foreign branch choice was rejected, a forged locked branch property returned HTTP 419, and device and role revocations took effect on component requests.

## Browser and production build

- The production asset build passed.
- Chrome via Playwright rendered the paired workspace at 1440, 768 and 360 px. The layout also fit a 720 px viewport, the available CSS width of a 1440 px viewport at 200% zoom.
- Overview and stock fit all checked viewports. On the 360 px stock screen, SKU, quantity and value were visible together.
- Keyboard checks covered Enter to open and navigate, focus inside the open dialog, and Escape returning focus to the menu trigger.
- A paired session switched to its authorized second branch and kept it after reload. Stock reflected the selected branch. A forged choice was rejected with an inline error, and the current branch stayed selected.
- Screenshot review compared the shared layout, branch placement, palette, typography and stock table with the mockup. Calculated contrast ratios were 14.03:1 for page text, 5.98:1 for the primary button, 9.88:1 for the branch banner and 7.07:1 for the validation alert.
- The screenshots use synthetic inventory and a disposable paired session in the dedicated MySQL test database.

| View | Desktop 1440 px | Tablet 768 px | Phone 360 px |
|---|---|---|---|
| Reference overview | [Desktop](screenshots/reference-overview-1440.png) | [Tablet](screenshots/reference-overview-768.png) | [Phone](screenshots/reference-overview-360.png) |
| Reference POS | [Desktop](screenshots/reference-pos-1440.png) | [Tablet](screenshots/reference-pos-768.png) | [Phone](screenshots/reference-pos-360.png) |
| Reference inventory | [Desktop](screenshots/reference-list-1440.png) | [Tablet](screenshots/reference-list-768.png) | [Phone](screenshots/reference-list-360.png) |
| Workspace overview | [Desktop](screenshots/workspace-overview-1440.png) | [Tablet](screenshots/workspace-overview-768.png) | [Phone](screenshots/workspace-overview-360.png) |
| Workspace stock | [Desktop](screenshots/workspace-stock-1440.png) | [Tablet](screenshots/workspace-stock-768.png) | [Phone](screenshots/workspace-stock-360.png) |

[Branch validation on phone](screenshots/workspace-branch-validation-360.png)

## Reproduce

From the application directory, build assets with `npm run build` and start the local app with `php artisan serve`. Pair an authorized test device through the existing `POST /auth/login` browser session endpoint using a CSRF token from `GET /auth/csrf`. Open `/workspace` and `/workspace/stock` in that session. The sign-in screen is scheduled for M29.

Use `npx --yes --package @playwright/cli playwright-cli open http://127.0.0.1:8000 --browser=chrome` to open a browser session. Use the CLI's `run-code` command with `page.setViewportSize({width:360,height:800})` to reproduce the narrow render.

Run the feature checks against the configured MySQL test database.

```powershell
php artisan test --compact '--filter=WorkspaceTest|DeviceAuthenticationTest|BranchAccessTest'
```
