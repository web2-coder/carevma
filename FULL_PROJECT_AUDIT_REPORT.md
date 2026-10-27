# CareVMA Full Project Audit & Fixed Patch

## Scope audited

The entire current CareVMA project tree was scanned for timezone/date handling, attendance, schedules, invoice generation/PDFs, report filters, frontend formatting, API failures, validation, scheduled tasks, notifications, emails, mutation actions, and destructive operations. The audit was performed against the effective project state after the previously supplied CareVMA patch chain, not the original clean ZIP alone.

## Core rules enforced

- Application/database timestamp base is UTC.
- Client/Doctor IANA timezone is the canonical timezone for client-owned schedules, attendance, invoice periods, invoice PDFs, client reports, and client-facing date/time displays.
- VA timezone is a converted display of the same instant; it does not replace the Doctor/Client calculation source.
- DST is handled through IANA timezone names (for example `America/New_York`), never fixed offsets.
- CareVMA-wide administrative/business-only dates use `CARE_VMA_BUSINESS_TIMEZONE` (default `Asia/Karachi`).
- Historical attendance snapshots preserve shift boundaries and calculation timezone so later schedule edits do not rewrite history.

## Error handling fixes

- Central API exception rendering returns friendly messages for validation, authentication, authorization, missing records, conflicts, CSRF/session expiry, throttling, database errors, and unexpected server errors.
- Database/exception technical details are logged internally with request context and are not returned to end users.
- Frontend API errors are normalized centrally and always produce a user-friendly toast unless the user intentionally cancels an action.
- Added branded CareVMA 403, 404, 419, 429, 500, and 503 server-rendered error pages.
- User-triggered notification failures now show actionable messages instead of silently failing.

## Confirmation and feedback fixes

- Central reusable confirmation modal intercepts all SPA `PUT`, `PATCH`, and `DELETE` requests.
- Confirmed POST-based update/review flows are also protected.
- Delete dialogs explicitly state that deletion cannot be undone.
- Cancel is silent and does not create a false red error.
- Successful confirmed mutations show success toasts; failures show error toasts.
- Old page-level `window.confirm()` calls were removed to avoid double confirmation.

## Timezone fixes

- Added centralized backend `ClientTimezoneService` and frontend `timezone.js` utility.
- Client-local date filters are converted to UTC query boundaries before querying timestamps.
- Admin Attendance and VA Attendance handle multi-client records using each record/client's current IANA timezone.
- Doctor Attendance filters use Doctor-local date boundaries.
- Client Daily Work Report uses the same client-local semantics.
- Admin dashboard attendance trend/today counts use per-client local dates instead of server/Karachi date.
- Schedule source timezone is the Client/Doctor timezone; VA time remains a converted display.
- Check-in window, late time, overtime, and schedule occurrence calculations remain Doctor/Client sourced and DST-aware.
- VA check-in errors include both Doctor time and converted VA time when helpful.
- Auth payloads return the assigned timezone for each role.
- Client timezone updates synchronize schedule timezone metadata without changing stored wall-clock schedule values.
- Application timezone is forced to UTC; business timezone is configured separately.

## Invoice fixes

- Automatic invoice command evaluates each client in that client's own timezone.
- 25th -> next month 26th cycle is derived in client-local dates; automatic processing is gated on client-local 27th 00:15.
- Manual custom-date invoices remain supported.
- Invoice attendance selection converts client-local period boundaries to UTC before querying `clock_in`.
- Duplicate-period attendance exclusion uses the same client-local -> UTC boundary logic.
- Invoice number month is based on the client-local month.
- Fixed a runtime bug where invoice numbering referenced an undefined `$client` variable.
- Invoice PDF date and attendance timestamps are rendered in the Client/Doctor timezone.

## Attendance history integrity

- Added `shift_start_snapshot` and `shift_end_snapshot` UTC columns to attendance.
- New clock-ins snapshot the exact shift occurrence.
- Clock-out and recalculation use the historical snapshots first, with legacy fallback only when needed.
- This prevents later schedule edits from changing old late/overtime/invoice calculations.

## Other issues fixed during audit

- Corrected VA Attendance dual-time display to use explicit employee timezone instead of reusing the Doctor calculation snapshot.
- Fixed schedule assignment matching across day rollover (`Karachi Tuesday` vs `New York Monday`) to prevent duplicate weekly schedules.
- Client timezone metadata is synchronized to linked schedules after a client timezone update.
- Legacy active-controller fallbacks no longer use application/server timezone as a client-time fallback.
- Attendance-change/status emails include the correct Doctor/Client timezone context.
- Frontend business-date defaults no longer depend on the browser's local timezone.

## Changed files and purpose

- `.env.example` — UTC application base and separate CareVMA business timezone configuration.
- `app/Console/Commands/GenerateMonthlyInvoices.php` — per-client timezone automatic invoice gate and period calculation.
- `app/Http/Controllers/Admin/Client/AdminClientController.php` — legacy client timezone fallback cleanup and friendly errors.
- `app/Http/Controllers/Admin/Client/AdminClientControllerold.php` — legacy duplicate controller timezone/error cleanup.
- `app/Http/Controllers/Api/AdminApiController.php` — client-local attendance filters, dashboard metrics, schedule/client timezone handling, friendly operational failures, historical recalculation.
- `app/Http/Controllers/Api/AuthApiController.php` — role-assigned timezone payload and business/employee-local date handling.
- `app/Http/Controllers/Api/DoctorApiController.php` — Doctor-local attendance filters/displays and client-local current time.
- `app/Http/Controllers/Api/EmployeeApiController.php` — Doctor-sourced check-in/late/OT, VA conversion, multi-client local filters, historical shift snapshots, correction timezone handling.
- `app/Http/Controllers/Api/InvoicePdfController.php` — passes canonical client timezone into PDF rendering.
- `app/Http/Controllers/EmployeeClientScheduleController.php` — legacy schedule timezone fallback and user-friendly errors.
- `app/Mail/AttendanceChangeRequestMail.php` — Doctor/Client timezone context for attendance-change email.
- `app/Mail/AttendanceStatusMail.php` — Doctor/Client timezone context for attendance-status email.
- `app/Models/Attendance.php` — shift snapshot fields/casts and UTC timestamp persistence compatibility.
- `app/Services/BirthdayReminderService.php` — explicit CareVMA business-time fallback.
- `app/Services/BirthdayService.php` — explicit employee/business timezone handling.
- `app/Services/ClientTimezoneService.php` — central IANA/DST conversion, local-date, UTC-boundary, display utilities.
- `app/Services/InvoiceGenerator.php` — client-local invoice boundaries, duplicate exclusion, invoice numbering fix.
- `bootstrap/app.php` — centralized safe API exception rendering/logging.
- `config/app.php` — application timezone forced to UTC.
- `config/carevma.php` — dedicated CareVMA business timezone config.
- `database/migrations/2026_10_27_220000_add_shift_snapshots_to_attendances.php` — historical shift UTC snapshots and safe legacy backfill.
- `resources/css/carevma-professional-theme.css` — confirmation modal/toast styles.
- `resources/js/api.js` — centralized mutation confirmations, error normalization, global success/error toast behavior.
- `resources/js/app.jsx` — mounts global feedback host.
- `resources/js/components/AppShell.jsx` — timezone-safe notification fallback and user-facing notification action errors.
- `resources/js/components/GlobalFeedbackHost.jsx` — reusable Confirm/Cancel modal and toast host.
- `resources/js/components/SalaryConverter.jsx` — business-timezone-safe FX timestamp display.
- `resources/js/components/ui.jsx` — timezone-explicit shared date/time formatting.
- `resources/js/feedback.js` — reusable confirmation/toast rules and resource-aware messages.
- `resources/js/pages/ClientProfile.jsx` — Doctor/VA timezone display, client attendance/report handling, day-rollover schedule matching.
- `resources/js/pages/Doctor.jsx` — explicit Doctor/VA attendance timezone display.
- `resources/js/pages/Employee.jsx` — VA/Doctor dual-time attendance display and correction timezone fixes.
- `resources/js/pages/EmployeeWizard.jsx` — normalized API failure/cancel handling.
- `resources/js/pages/Management.jsx` — client-local attendance display, business-timezone defaults, centralized confirmation behavior.
- `resources/js/timezone.js` — frontend IANA/DST timezone utility with no implicit browser-zone fallback for domain data.
- `resources/views/emails/attendance/status.blade.php` — timezone label/context in branded attendance status email.
- `resources/views/emails/attendance_change_request.blade.php` — timezone-aware attendance request details.
- `resources/views/errors/403.blade.php` — friendly access-denied page.
- `resources/views/errors/404.blade.php` — friendly missing-page/record page.
- `resources/views/errors/419.blade.php` — friendly expired-session page.
- `resources/views/errors/429.blade.php` — friendly throttling page.
- `resources/views/errors/500.blade.php` — friendly unexpected-server-error page.
- `resources/views/errors/503.blade.php` — friendly maintenance/unavailable page.
- `resources/views/errors/layout.blade.php` — shared branded error-page shell.
- `resources/views/invoices/invoice.blade.php` — Client/Doctor-local PDF date/clock rendering.
- `routes/console.php` — per-minute scheduler entry; invoice command performs per-client local-time gate.

## Apply

Extract this ZIP at the CareVMA project root and overwrite matching files, then run:

```powershell
php artisan optimize:clear
php artisan migrate
npm install
npm run build
php artisan schedule:list
php artisan serve
```

Production Laravel scheduler must continue running every minute (`php artisan schedule:run` from cron/supervisor) because automatic invoice eligibility is now evaluated per client-local timezone.

Recommended environment values:

```env
APP_TIMEZONE=UTC
CARE_VMA_BUSINESS_TIMEZONE=Asia/Karachi
VITE_DEFAULT_TIMEZONE=Asia/Karachi
```

Do not copy a development `.env` over production; only add/update the relevant variables.

## Verification performed

- PHP syntax lint passed for 178 PHP files in `app`, `bootstrap`, `config`, `routes`, and migrations.
- JavaScript/JSX parser validation passed for 23 source files using Babel parser.
- Residual scan found no active raw exception-message leaks in API/user responses.
- Residual scan found no `config('app.timezone')` dependency in project controllers/routes after cleanup.
- Residual React scan found no old `window.confirm()` calls.
- A full production Vite build could not be completed in this Linux container because the available dependency bundle came from Windows and lacked Rollup's Linux optional native package. Laravel runtime boot was also blocked in this container by the installed PHP environment missing `mbstring`. These are verification-environment limitations; they are not reported as passing tests.

## Acceptance checklist

- [x] Central, friendly validation/API/network/permission/server error messages.
- [x] Raw technical database/exception details are logged, not exposed to users.
- [x] Confirmation modal before SPA CRUD updates/deletes and confirmed POST update/review flows.
- [x] Success/error toast feedback for confirmed mutations.
- [x] UTC application/database timestamp base.
- [x] Central reusable backend and frontend timezone utilities.
- [x] Client/Doctor IANA timezone drives client-owned attendance, schedules, reports, invoices, and PDFs.
- [x] VA receives a converted local display of the same instant.
- [x] DST handled through IANA timezone conversion.
- [x] Client-local report/filter date ranges converted to UTC query boundaries.
- [x] Automatic invoices evaluated using each client's local timezone.
- [x] Invoice PDF timestamps rendered in client timezone.
- [x] Historical attendance shift boundaries snapshotted for stable recalculation/invoicing.
- [x] Full updated files included in this patch plus a unified diff and changed-file manifest.
