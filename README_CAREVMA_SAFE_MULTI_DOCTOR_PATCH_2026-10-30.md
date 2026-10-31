# CareVMA Safe Multi-Doctor Work Session + Additional Updates Patch

Date: 2026-10-30
Base project: `carevmalive(6).zip`

## What this patch changes

This patch is additive and keeps existing historical attendance records intact.

### 1. One VA work session, multiple Doctor segments
- A VA checks in once.
- The first selected Doctor starts the parent VA work session.
- Other assigned Doctor shifts automatically join when their Doctor-local start time is reached.
- Each Doctor has an independent attendance segment (`client_id`, `schedule_id`, timezone/shift snapshots, late, break allocation, regular minutes, overtime and approval state).
- Earlier Doctor segments stop regular work at their own shift end while the VA can remain checked in for later Doctor shifts.
- The latest-ending Doctor can continue into the existing CareVMA overtime workflow when the VA remains after that Doctor shift end.
- Live Workforce counts the parent VA work session once, not once per Doctor segment.

### 2. Shared break with Doctor-wise overlap
- A real VA break is stored once on the work session.
- The patch mirrors only the overlapping portion into each Doctor attendance segment.
- A break after a Doctor shift has ended is not deducted from that Doctor.

### 3. Doctor-specific late rules
- VA check-in 09:20, Doctor A starts 09:00 => Doctor A late 20m.
- Doctor B starts 10:00 while the VA is already active => Doctor B late 0m.
- VA first checks in 10:15 => 09:00 Doctor late 75m and 10:00 Doctor late 15m.

### 4. Backend reconciliation
- A Laravel scheduled reconciliation runs every minute.
- Employee dashboard/API requests also reconcile the current work session.
- This prevents correctness from depending only on a React timer or the browser staying open.
- Existing legacy auto-checkout remains available for attendance rows that do not belong to the new parent work-session model.

### 5. Schedule / shift change requests
- VA can submit a schedule change request from **My Schedule**.
- Admin/HR Change Requests shows attendance corrections and schedule/shift requests.
- Admin/HR may override requested clock-in/out or requested schedule date/start/end before approving.
- Approved attendance override is saved to the attendance record and passed through CareVMA recalculation.

### 6. Early / late check-in comments
- The VA Check In action now opens a small modal with an optional reason/comment.
- Comment is stored on the parent work session and Doctor attendance record.

### 7. Timezone refresh without hard page reload
- Client/Doctor timezone saves dispatch an in-app timezone update event.
- Auth and relevant schedule/dashboard views refresh their server data without requiring a browser hard reload.
- Existing periodic dashboard refresh continues to pick up server-side changes from other users/browsers.

### 8. First-month invoice proration
- Invoice attendance is not billed before the VA's actual `date_of_joining`.
- The employee salary line `period_start` becomes the later of invoice start date or joining date.
- Invoice PDF attendance calendar starts from that effective employee date.
- Invoice month is based on the invoice cycle/end-date month rather than the date the invoice happened to be generated.

### 9. Invoice PDF / email
- Invoice item table uses **Working Hours**.
- Separate `Regular Work`, `Approved OT`, and `Payable Time` line items are removed from the item table.
- Attendance **Total Hours** shows regular payable shift time.
- **Payable Hours** remains regular payable + approved overtime only.
- Emailed attachment filename includes the Doctor name, for example `Dr_Harram_Invoice_Oct_2026.pdf`.
- Pending/rejected overtime remains non-billable.

### 10. Doctor dashboard privacy
- VA location is removed from Doctor dashboard UI.
- VA location is also hidden from the Doctor API payload used by those views.

### 11. Invoice Doctor search
- Existing searchable Doctor selectors remain in invoice filters and single invoice generation.
- Bulk invoice Doctor selection now also has a search box.

### 12. Sidebar layout fix
- The existing single `AppShell` sidebar is retained.
- Final CSS synchronizes desktop expanded/collapsed sidebar width with the AppShell grid and preserves the mobile drawer width.
- No duplicate sidebar component is introduced.

## Database safety

The migration is additive:
- creates `work_sessions`
- creates `work_session_breaks`
- creates `schedule_change_requests`
- adds nullable work-session/comment fields to attendance
- adds nullable parent-break reference to employee breaks
- adds nullable Admin-approved time fields to attendance change requests
- adds uniqueness guards for Doctor segments/break mirrors

It does **not** truncate attendance, recreate users, delete historical attendance, or run `migrate:fresh`.

## Apply locally (Windows / PowerShell)

Extract this patch over your latest CareVMA project so the folders merge by project-relative path.

```powershell
cd C:\xampp\htdocs\carevmalive

git status
php artisan migrate
php artisan test --filter=DoctorSegmentMathTest
npm run build
git status
```

If your local PHP test command reports missing PHP extensions, enable the normal Laravel test extensions in `php.ini` (notably `mbstring`, `dom`, `xml`, and `xmlwriter`) and rerun the test.

### Git push

```powershell
cd C:\xampp\htdocs\carevmalive

git add app
git add database
git add routes
git add resources
git add tests
git add README_CAREVMA_SAFE_MULTI_DOCTOR_PATCH_2026-10-30.md

git add -f public/build/manifest.json
git add -f public/build/assets/

git status
git commit -m "Add safe multi-doctor work sessions and billing updates"
git push origin main
```

`public/build` must be generated on the local machine before pushing because the live Hostinger server does not have npm available.

## Pull on live server

```bash
cd /home/u671369435/domains/carevma.com/public_html/app

git status
git switch main
git pull origin main

php artisan migrate --force

php artisan config:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

php artisan migrate:status
git log -1 --oneline
git status
```

Do **not** run:
- `php artisan key:generate`
- `php artisan migrate:fresh`
- destructive table truncation/reset commands
- `php artisan optimize:clear`

## Scheduler requirement

The existing server cron must continue calling Laravel Scheduler every minute, for example:

```bash
php /home/u671369435/domains/carevma.com/public_html/app/artisan schedule:run
```

The patch registers `carevma-work-session-reconcile` every minute. Dashboard/API reconciliation is also present as a safety fallback, but the scheduler is required for Doctor shifts to activate/finalize correctly even when the VA browser is closed.

## Verification checklist

1. One VA can have overlapping Doctor schedules.
2. Check in once on the first Doctor.
3. Later Doctor appears automatically after its start time.
4. Later Doctor is not falsely late when VA was already working.
5. Earlier Doctor stops regular calculation at its own end time.
6. Break 11:00-11:30 during two active Doctors deducts from both; break after one Doctor ends deducts only from the remaining Doctor.
7. Live Workforce shows the VA once and can display multiple Doctor names.
8. Doctor sees only their own attendance records and no VA location.
9. Admin attendance correction can override requested times and recalculates billing/overtime.
10. First-month invoice starts at joining date.
11. PDF shows Working Hours and only approved OT changes payable hours.
12. Email attachment contains Doctor name.
13. Desktop sidebar expanded/collapsed and mobile drawer remain aligned.

## Rollback note

The migration includes a `down()` path. Use rollback only if you are intentionally removing this feature and no production work-session/change-request data needs to be retained. Do not casually rollback after the feature has started receiving live records.
