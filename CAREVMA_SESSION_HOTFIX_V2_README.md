# CareVMA Session / CSRF Hotfix V2

This hotfix addresses the recurring 419 / token mismatch loop.

## Why the old message was still visible
The old compiled file in `public/build` contained:
`Your session security token expired. Refresh the page and try again.`

Replacing `resources/js` without running a Vite production build leaves that old bundle active.

## Important live `.env` settings
For a single-host portal at `https://app.carevma.com`, use a host-only session cookie:

```env
APP_URL=https://app.carevma.com
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_PATH=/
SESSION_DOMAIN=
SESSION_COOKIE=carevma_portal_session_v2
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Do not use `SESSION_DOMAIN=app.carevma.com8`.

## Deploy commands

```bash
php artisan optimize:clear
rm -rf public/build
rm -rf node_modules
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Then verify the stale string is gone:

```bash
if grep -R "Your session security token expired" public/build/assets 2>/dev/null; then
  echo "STALE BUILD STILL PRESENT"
else
  echo "NEW BUILD ACTIVE"
fi
```

The frontend now obtains the CSRF token directly from the current Laravel session and sends it as `X-CSRF-TOKEN`. It no longer depends on a possibly stale/duplicate `XSRF-TOKEN` browser cookie.
