# Upgrade & Deployment Guide

## Existing CareVMA database

1. Back up database, `.env`, uploaded `storage/app/public`, and current release.
2. Deploy code to staging first.
3. Preserve the existing `APP_KEY`.
4. Preserve the existing production `.env`; add new settings only as needed.
5. Run `composer install --no-dev --prefer-dist --optimize-autoloader` on production or `composer install` on staging/local.
6. Run `npm ci && npm run build` when a compatible lockfile has been generated in your release process; otherwise use `npm install && npm run build` for the first upgraded build and commit the generated lockfile afterward.
7. Run `php artisan migrate:status`.
8. Run `php artisan migrate` — never `migrate:fresh` on the existing installation.
9. If `public/storage` is a regular text file from an old flattened archive, delete that file and run `php artisan storage:link`.
10. Run `php artisan optimize:clear`, then rebuild appropriate production caches.
11. Verify all roles and ownership checks with synthetic/staging records.

## Upgrade migration behavior

`2026_10_23_120000_upgrade_carevma_for_react_hr.php`:

- extends the `users.role` enum with `hr` on MySQL/MariaDB;
- adds optional client profile-picture support;
- adds `client_id`, `schedule_id`, timezone snapshot and overtime review fields to attendance;
- removes the legacy employee/date unique index on MySQL/MariaDB so a VA can record separate client-owned sessions in one day;
- adds correction-request reviewer metadata;
- does not rewrite historical attendance ownership automatically.

Historical attendance rows may have `client_id`/`schedule_id` null because the old system did not store that ownership. The application does not invent ownership for those historical rows.

## Staging acceptance

Verify in this order:

1. Login as Admin, HR, Employee and Client/Doctor; verify cross-role routes are denied.
2. Admin creates/edits an HR account; HR can sign in but cannot open client/invoice administration.
3. Admin/HR creates and reopens an Employee; login identity stays synchronized.
4. Admin creates a Client and schedule; employee sees the schedule.
5. Employee checks in within the allowed window, starts/ends breaks and checks out.
6. Verify late minutes, regular minutes and overtime.
7. Employee submits a correction; Admin/HR approves/rejects once only.
8. Admin/HR approves or partially approves overtime.
9. Admin generates an invoice from client-owned completed attendance.
10. Client sees only assigned employees, their permitted attendance and their own invoices.
11. Render invoice PDF and confirm the CareVMA image is embedded.
12. Upload/open/remove employee profile media after `storage:link`.
13. Verify browser Back/Forward and direct refresh on every React route.
14. Verify old bookmarked URLs redirect inside the React application rather than returning 404.

## Rollback

The upgrade migration intentionally has a non-destructive `down()` because dropping the added ownership/review columns can destroy operational history. Roll back by restoring the pre-release code and database backup rather than running destructive reverse migrations.
