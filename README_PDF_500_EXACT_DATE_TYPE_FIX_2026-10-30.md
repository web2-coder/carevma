# CareVMA PDF 500 Exact Date-Type Fix — 2026-10-30

## Confirmed production error
`Call to a member function toDateString() on string` in `app/Services/InvoicePdfService.php`.

## Fix
- Normalizes invoice `period_start` / `period_end` whether they are strings or DateTime/Carbon values.
- Normalizes salary period dates with the same safe helper.
- Keeps existing live-schema guards intact.
- No database migration required.

## Apply
Extract this ZIP over the CareVMA project root.

Then run:

```bash
php -l app/Services/InvoicePdfService.php
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

No npm build is required for this focused backend-only PDF fix.
