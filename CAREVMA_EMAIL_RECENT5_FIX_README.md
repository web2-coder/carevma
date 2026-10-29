# CareVMA Email + Recent Attendance Fix

## Recent attendance
- Admin/HR dashboard API now returns only the latest 5 attendance rows.
- React dashboard also applies `slice(0, 5)` as a UI safety cap.

## Email delivery
Laravel logs showed two real SMTP problems:
- `535 5.7.8 Authentication failed` (invalid/stale SMTP credentials)
- `550 5.7.0 Too many emails per second` (Mailtrap testing-plan rate limit)

Code changes:
- SMTP send slots are now serialized and spaced by 3 seconds.
- Throttling uses a filesystem lock under `storage/framework/cache`, so it does not depend on the database cache connection.
- Rate-limited sends are retried.
- SMTP errors returned to the UI are sanitized but more actionable.

## Live deployment
After replacing files:

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan carevma:mail-diagnostics
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

If diagnostics still show Mailtrap while `.env` is supposed to use Titan/another production SMTP provider, the live server is using stale/different configuration. Correct `.env`, then run `php artisan config:clear` before caching config again.

A `535 Authentication failed` response cannot be repaired by application code: the live `MAIL_USERNAME` / `MAIL_PASSWORD` must be valid for the configured SMTP host.
