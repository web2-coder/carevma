# CareVMA High-Volume Stability & Performance Safe Patch

Purpose: harden the current CareVMA build for high data growth (for example 100,000+ new records/week) without changing attendance, overtime, invoice, timezone, offboarding, profile, mobile UI, or billing business rules.

## Included
- Non-destructive composite indexes for high-growth tables (attendance, breaks, work sessions, schedules, invoices, salary lines, activity logs, change requests, notifications, finance).
- Index-friendly invoice month filtering (removes DATE_FORMAT/COALESCE scans from invoice list/overview).
- Dashboard birthday lookup pre-filter so all employees are not hydrated on every dashboard refresh.
- Doctor invoice run-details popup changed from per-doctor 3N queries to bulk schedule/open-attendance/invoice queries.
- Activity Log actor/module filter options cached briefly with safe fallback if cache is unavailable.
- Central server-side pagination hard cap remains 100 by default and can be overridden with CARE_VMA_MAX_PER_PAGE later.
- Critical work-session and invoice transactions retry transient deadlocks up to 3 attempts.

## Deployment
1. Overlay this ZIP at the Laravel project root.
2. Back up the database first.
3. Run during a lower-traffic window because creating indexes on an already-large table can use database resources.
4. Run:
   php artisan migrate --force
   php artisan optimize:clear

No npm build is required; this patch contains no frontend files.

## Safety
- Never run `php artisan migrate:fresh` on live data.
- `.env` and `.htaccess` are not included.
- The migration does not delete or rewrite existing records.
- Migration down() intentionally does not drop performance indexes, preventing a rollback from locking large live tables.

Optional environment overrides (do not add unless needed):
CARE_VMA_MAX_PER_PAGE=100
CARE_VMA_ACTIVITY_FILTER_CACHE_SECONDS=300
