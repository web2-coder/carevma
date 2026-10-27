# CareVMA Final Audit Report

## Final audit scope

The final pass reviewed the Laravel backend, React SPA, role routing, onboarding, timezone handling, attendance/break calculations, schedules, realtime notifications, client workspace, invoice access, table tooling, branding, birthday UI, and deployment defaults.

## Important fixes consolidated

- Restored missing SPA core files (`AppShell`, brand logo and live clock components) that were imported but absent from the uploaded ZIP.
- New Admin/HR-created VA accounts now remain `profile_completed = false` until required self-service fields are completed.
- Added frontend and backend profile-completion guards, including HTTP 423 API protection for incomplete VA accounts.
- Fixed legacy role redirects so Client/Doctor goes to `/doctor/dashboard`, HR goes to `/hr/dashboard`, and incomplete VAs go to `/employee/profile`.
- Restored the legacy Client password-change controller action referenced by the retained doctor route file, removing a dangling route/controller method reference.
- Removed browser timezone as a source of truth for the navbar clock. Assigned IANA timezone is used instead.
- Simplified trusted time to server UTC epoch; no external time API is required for core attendance/schedule timing.
- Attendance and break timestamps are treated as UTC database instants, preventing timezone-offset inflation in live timers.
- Live session and break timers use backend elapsed-second anchors so refreshes and break transitions do not reset counters.
- Check-in is limited to the 15-minute pre-shift window and the active shift, and a completed schedule occurrence cannot be checked in again.
- Fixed schedule recurrence semantics: Weekly schedules recur; new Once schedules use an exact VA-local date and do not roll forward, while legacy undated rows remain backward-compatible.
- Same-day multiple attendance sessions remain supported through the dedicated migration.
- Work-minute calculations use minute precision rather than seconds; invoice displays use minutes while amount calculation remains hourly-rate based.
- Doctor invoice navigation/API access remains disabled; invoice PDF is Admin/Superadmin-only.
- Doctor attendance is displayed in the Doctor/Client assigned timezone instead of hardcoded UTC.
- Doctor timezone changes refresh authenticated user state so the navbar clock updates without a new login.
- Realtime Pusher uses private per-user channels. Notification and Live Workforce badge updates have polling fallback.
- Incomplete VA accounts no longer have notification API/page access before profile completion.
- Probation reminders no longer use `admin@example.com`; they target actual Admin/Superadmin/HR users and create CareVMA operational notifications/emails.
- Added safe `.env.example` with no private application key or Pusher secret.
- Preloader is not part of the React SPA; normal direct app loading is preserved.

## Static QA completed

- PHP syntax lint: PASS across 163 PHP files in `app`, `bootstrap`, `config`, `database`, and `routes`.
- React/JS/JSX Babel parse: PASS across 19 source files.
- Local JavaScript import resolution: PASS, no missing relative imports.
- Route/controller static method check: PASS across 107 route-controller references.
- CSS brace-balance check: PASS across 11 CSS files.
- No duplicated `durationText()` helper or escaped `\\*` multiplication operator remains in the active React code.

## Environment-specific runtime validation limits

The audit container could not perform a full Laravel Artisan boot because its PHP runtime does not include `mbstring`. The uploaded `node_modules` directory was created on Windows and lacks the Linux Rollup optional binary, so a Linux Vite production build cannot reuse that folder. These are environment/dependency issues, not source syntax errors.

On the target Windows/XAMPP environment, run `composer install` and `npm install` before the final runtime/build test.

## Database safety

Use normal `php artisan migrate`. Do not use `migrate:fresh`, `db:wipe`, or destructive reset commands against an existing CareVMA database.
