# CareVMA Session / CSRF Fix

## Root cause found
The uploaded live project `.env` contains an invalid cookie domain:

```env
SESSION_DOMAIN=app.carevma.com8
```

The trailing `8` prevents the browser from accepting the Laravel session cookie for `app.carevma.com`. That causes repeated 419 / TokenMismatchException responses, login loops, and pages asking for refresh.

## Required live `.env` values
Use:

```env
APP_URL=https://app.carevma.com
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_PATH=/
SESSION_DOMAIN=app.carevma.com
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Then run:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

## Code hardening included
- Login explicitly primes a CSRF token tied to the current session before POSTing credentials.
- Mutating API requests prime the XSRF cookie when missing.
- 419 responses refresh CSRF once and retry the original request automatically.
- CSRF refresh is single-flight to avoid parallel refresh races.
- Logout primes the new guest session so the next login works without a hard refresh.
- `config/session.php` rejects a malformed SESSION_DOMAIN that cannot own APP_URL and safely falls back to the APP_URL host.
- CSRF token endpoint is marked no-cache.

After deployment, clear old cookies for `app.carevma.com` once if the browser still has stale cookies from the broken configuration.
