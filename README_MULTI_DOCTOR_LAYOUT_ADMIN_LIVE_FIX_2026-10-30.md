# CareVMA Safe Multi-Doctor Layout + Admin Live Workforce Fix

## Fixes
- VA dashboard: full-width multi-doctor live section; two Doctor cards on wide screens, one per row on smaller screens.
- Prevents current time, timezone, shift, and Doctor timer text from collapsing into narrow vertical columns.
- Admin Live Workforce: responsive multi-doctor cards and per-Doctor timer presentation.
- Admin `/management/live-users` is hardened so a single reconciliation failure does not take down the entire workforce page.
- Adds per-Doctor `clock_in_utc` and `elapsed_seconds` to the live workforce payload.
- If the work-session feed itself unexpectedly fails, Laravel logs the exact error and the controller falls back instead of returning a raw 500.

## Database
No migration required.

## Build
Run `npm run build` locally on Windows after copying the patch, then include `public/build` in the Git push.
