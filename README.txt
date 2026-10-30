CareVMA - Old Working Direct Mail Restore Patch
Date: 2026-10-29

Purpose
- Restores the old project's direct Laravel SMTP send pattern: Mail::to(...)->send(...)
- Applies to VA/Employee activation invitations, Doctor/Client invitations, HR invitations, and invoice PDF email.
- Preserves the current 24-hour activation/token logic from the newer CareVMA controller.
- Does NOT include or overwrite .env credentials.
- Does NOT change database, sessions, timezone calculations, invoices, schedules, birthdays, or frontend.

Replace
app/Http/Controllers/Api/AdminApiController.php

After replacing on local project:
php -l app/Http/Controllers/Api/AdminApiController.php
git add app/Http/Controllers/Api/AdminApiController.php
git commit -m "Restore direct Laravel SMTP mail sending"
git push origin main

On live server:
cd /home/u671369435/domains/carevma.com/public_html/app
git pull --ff-only origin main
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

Important
If SMTP returns 535 authentication failed after this patch, the PHP code is reaching Titan correctly but the current SMTP username/password is rejected. In that case fix the Titan mailbox credentials in .env; do not change application code again.
