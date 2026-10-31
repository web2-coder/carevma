# CareVMA Safe Invoice 500 + Searchable Doctors/Clients Patch
Date: 2026-10-30

## Scope
This patch is based on the supplied current `carevmalive(7).zip`.

### 1. Manual invoice 500 hardening
- Makes `InvoiceGenerator` compatible with live databases where optional newer invoice/salary columns may not yet exist.
- Guards `invoices.status`, `billing_cycle_month`, `auto_generated`, and `generated_at` usage.
- Guards `employee_salaries.invoice_id`, `total_late`, and `total_overtime` usage.
- Guards optional employee `date_of_joining` selection.
- Does NOT delete or rewrite existing invoice, attendance, salary, or client data.
- Manual invoice failures are logged as `Manual invoice generation failed` and returned to the SPA as a readable validation/API message rather than a generic CareVMA 500 page.

### 2. /admin/invoices Doctors / Clients selector
- Replaces the always-expanded bulk checkbox list with a dropdown.
- Search stays at the top inside the dropdown.
- Supports selecting multiple Doctors / Clients.
- Shows selected count, Clear, and Done controls.
- Existing invoice filters and Generate Invoice modal searchable selects remain unchanged.

## Files changed
- app/Http/Controllers/Api/AdminApiController.php
- app/Services/InvoiceGenerator.php
- resources/js/components/ui.jsx
- resources/js/pages/Management.jsx
- resources/css/carevma-professional-theme.css

## Safe apply
Extract this patch over the current project root.

Then on LOCAL Windows PC:

```bat
cd C:\xampp\htdocs\carevmalive
npm run build
php -l app\Services\InvoiceGenerator.php
php -l app\Http\Controllers\Api\AdminApiController.php
git status
git add app/Http/Controllers/Api/AdminApiController.php app/Services/InvoiceGenerator.php resources/js/components/ui.jsx resources/js/pages/Management.jsx resources/css/carevma-professional-theme.css
git add -f public/build
git commit -m "Fix invoice generation 500 and searchable client dropdown"
git pull --rebase origin main
git push origin main
```

On LIVE server:

```bash
cd /home/u671369435/domains/carevma.com/public_html/app
git switch main
git pull origin main
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

## If invoice generation still reports an error
Immediately run:

```bash
tail -n 120 storage/logs/laravel.log
```

Look for:

```text
Manual invoice generation failed
```

That log entry will contain the exact remaining database/data exception without exposing it in the browser.
