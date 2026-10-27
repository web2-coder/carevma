$ErrorActionPreference = "Continue"

Write-Host "== CareVMA environment check ==" -ForegroundColor Cyan
php -v
composer --version
node --version
npm --version

Write-Host "`nRequired PHP extensions" -ForegroundColor Cyan
$mods = php -m
foreach ($ext in @("mbstring", "fileinfo", "pdo_mysql", "openssl")) {
    if ($mods -contains $ext) { Write-Host "[OK] $ext" -ForegroundColor Green }
    else { Write-Host "[MISSING] $ext" -ForegroundColor Red }
}

Write-Host "`nLaravel checks" -ForegroundColor Cyan
php artisan about
php artisan route:list --path=api
php artisan migrate:status
