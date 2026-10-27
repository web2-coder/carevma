$ErrorActionPreference = "Stop"

Write-Host "CareVMA local setup" -ForegroundColor Cyan

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host "Created .env from .env.example" -ForegroundColor Green
}

$requiredExtensions = @("mbstring", "fileinfo", "pdo_mysql")
$phpModules = php -m
foreach ($extension in $requiredExtensions) {
    if ($phpModules -notcontains $extension) {
        throw "Missing PHP extension: $extension. Enable it in php.ini before continuing."
    }
}

composer install
npm install

if (-not (Test-Path "bootstrap/cache")) { New-Item -ItemType Directory -Force "bootstrap/cache" | Out-Null }
if (-not (Test-Path "storage/framework/cache/data")) { New-Item -ItemType Directory -Force "storage/framework/cache/data" | Out-Null }
if (-not (Test-Path "storage/framework/sessions")) { New-Item -ItemType Directory -Force "storage/framework/sessions" | Out-Null }
if (-not (Test-Path "storage/framework/views")) { New-Item -ItemType Directory -Force "storage/framework/views" | Out-Null }
if (-not (Test-Path "storage/logs")) { New-Item -ItemType Directory -Force "storage/logs" | Out-Null }

php artisan key:generate --force
php artisan optimize:clear

if (Test-Path "public/storage" -PathType Leaf) {
    Remove-Item "public/storage" -Force
}
php artisan storage:link

Write-Host "Dependencies installed. Configure DB_* values in .env, then run: php artisan migrate" -ForegroundColor Yellow
Write-Host "Development: Terminal 1 -> php artisan serve | Terminal 2 -> npm run dev" -ForegroundColor Cyan
