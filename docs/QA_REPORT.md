# CareVMA React/Laravel Upgrade — QA Report

Date: 23 October 2026

## Scope checked in this build

This QA pass covers the upgraded source package created from the uploaded CareVMA project. It focuses on source integrity, SPA routing, Laravel controller/route wiring, role boundaries, media-path handling, migration safety, and packaging hygiene.

## Passed checks

- **PHP syntax:** 155 PHP source files lint successfully with `php -l`.
- **JavaScript / JSX syntax:** 11 frontend JS/JSX files parse successfully with the TypeScript parser in JSX/JS mode.
- **Local frontend imports:** all relative JS/JSX imports resolve to files present in the package.
- **Laravel route/controller wiring:** every controller method referenced by `routes/web.php` exists; the invoice PDF invokable controller exists.
- **React navigation map:** 65 React route patterns are defined; 27 literal sidebar/navigation targets resolve to a React route.
- **SPA navigation audit:** ordinary portal navigation uses React Router. Remaining raw browser links are intentional server/document flows only: invoice PDF, employee uploaded documents, and Google OAuth.
- **Direct refresh support:** Laravel has a SPA catch-all route for portal URLs while excluding `/api`, `/storage`, `/build`, and `/up`.
- **Role separation:** Admin-only APIs are separated from Admin/HR management APIs; Employee and Client/Doctor APIs resolve their profile from the authenticated user.
- **Media fallback:** employee/client image URLs are normalized and React falls back to initials if a stored image URL fails.
- **Storage archive repair:** a flattened `public/storage` text-file placeholder is not included. Local/staging setup must create the real storage link with `php artisan storage:link`.
- **Packaging hygiene:** no `.env`, SQL dump, `vendor`, `node_modules`, or production uploaded records are included in the deliverable.
- **Public branding assets:** CareVMA favicon and PDF-compatible branding image are present.

## Important behavior repairs covered by source review

- React Router replaces full-page portal navigation.
- Admin, HR, Employee/VA, and Client/Doctor portals use role-protected routes.
- Admin can manage HR accounts without manual SQL insertion.
- New attendance sessions retain client and schedule ownership after the upgrade migration.
- The legacy employee/date attendance uniqueness restriction is removed on MySQL/MariaDB so separate client sessions can exist on the same day.
- Employee check-in validates ownership/enabled schedule and applies the 15-minute early window.
- Break, late, regular-time, and overtime calculations are handled server-side.
- Correction review rejects repeated decisions and resets stale overtime approval state when attendance is changed.
- Doctor attendance and invoice PDF attendance are client-scoped; historical attendance with unknown client ownership is not guessed/exposed.
- Employee signed onboarding is bound to the invited employee session instead of trusting a posted employee ID.
- Google connection/login validates the linked account rather than silently linking any matching request.
- Client password tokens are expiry-checked and removed after successful use.
- Duplicate legacy migrations for user role/department creation are guarded for fresh installs.

## Checks not executable in this sandbox

These are **not claimed as passed** and must be run on the user's XAMPP/staging machine:

1. **`composer validate` / Laravel boot:** Composer is not installed in this execution environment.
2. **Laravel feature tests / `route:list`:** this environment's PHP is missing `mbstring` and `pdo_mysql`, which Laravel/MySQL runtime requires.
3. **MySQL migration execution:** `pdo_mysql` is unavailable here. The upgrade migration is intentionally non-destructive, but it must still be exercised against a restored staging copy of the user's database.
4. **Vite production build:** the npm registry is not available from this environment. An offline lockfile attempt failed because `@vitejs/plugin-react` was not cached. JSX source parsing passed, but this is not a substitute for `npm run build`.
5. **Live integrations:** Google consent, SMTP, actual uploaded-file open/remove, production queues, and host storage symlink behavior require staging/live-compatible services.

## Required local/staging verification commands

From the project root on Windows/XAMPP:

```powershell
composer install
npm install
php artisan key:generate
php artisan storage:link
php artisan migrate:status
php artisan migrate
npm run build
php artisan test
php artisan route:list
```

For development:

```powershell
php artisan serve
npm run dev
```

A helper is also included:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\check-project.ps1
```

## Staging acceptance checklist

1. Sign in as Admin, HR, Employee and Client/Doctor.
2. Confirm direct URL refresh and browser Back/Forward work on every SPA route.
3. Confirm cross-role URLs/API requests return denial rather than data.
4. Admin creates/reopens an HR account; HR login succeeds.
5. Admin/HR creates/reopens an employee and schedule.
6. Employee checks in, starts/ends a break and checks out against an assigned client schedule.
7. Verify late, regular, break and overtime values.
8. Employee submits a correction; Admin/HR approves/rejects and cannot decide it twice.
9. Admin/HR approves full/partial overtime or rejects it.
10. Admin generates a client invoice; verify duplicate-period protection and totals with synthetic data.
11. Doctor sees only assigned employees, client-owned attendance and own invoices.
12. Open the invoice PDF and verify branding plus client-scoped attendance.
13. Upload a profile image/document; verify open, fallback, and removal behavior.
14. Exercise signed onboarding, password reset, Client set-password and Google OAuth on staging.
15. Verify historical records with null `client_id` remain intentionally unassigned instead of being exposed to a client.

## Production safety

For an existing CareVMA installation, do **not** use `db:wipe`, `migrate:fresh`, `migrate:refresh`, or blanket seeding. Back up the database, `.env`, uploaded storage and current release; test the upgrade migration and frontend build on staging first.
