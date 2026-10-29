#!/usr/bin/env bash
set -e

php artisan optimize:clear
rm -rf public/build
rm -rf node_modules
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart || true

echo
if grep -R "Your session security token expired" public/build/assets 2>/dev/null; then
  echo "ERROR: stale CareVMA build is still present."
  exit 1
fi

echo "CareVMA session hotfix deployed and fresh frontend build verified."
