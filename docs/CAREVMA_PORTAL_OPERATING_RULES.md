# CareVMA — Portal Operating Rules (Code-Aligned Guide)

**Source version:** `carevmalive-latest(5).zip`, including the already integrated code and this VA timer/documentation patch.

> This guide describes code-supported behavior and approved business requirements. A feature described as "requires verification" is not a claim that production acceptance testing has passed. The in-app Admin/HR Docs page reads `resources/js/docs.jsx`.

## 1. Roles and account access

- **Administrator:** manages staff, Doctors, scheduling, attendance review, billing, finance, options, permissions and logs.
- **HR:** may use only modules and actions permitted by role configuration; overtime approval must be authorized.
- **VA/Employee:** may view their personal schedule, check in/out, record breaks, request attendance/schedule corrections and access their own documents.
- **Doctor/Client:** may view authorized VA assignments, their Doctor-time attendance, invoices and shared documents. Never expose other clients' information.
- Account invitation and password setup must use restricted links. Disable/offboard access without deleting historical financial or workforce records. Some original-document registration/approval and salary-slip requirements are not fully verified.

## 2. Employees and Doctors

- Employees may be VAs or other staff types. An employee may have multiple Doctors, and a Doctor may have multiple VAs.
- VA-to-Doctor assignments carry schedule day/date, times, timezone, break allowance and rates. A disabled assignment can remain relevant to historical attendance with valid schedule links.
- Profile photo URL must resolve from the stored public-disk path; confidential documents must be protected by authorization, not public storage alone.

## 3. Scheduling and timezones

- The Doctor's IANA timezone is the time-calculation authority for that Doctor's shift; show VA-local time as a converted convenience display.
- A check-in window opens up to **15 minutes before** shift start. Early arrival never increases regular billable minutes.
- Specific-date assignments apply only on their selected dates. Overnight work and daylight-saving changes must be calculated using actual instants, not fixed offsets.
- Several Doctor segments can belong to one VA work session; each Doctor segment has independent attendance limits and billable overtime.

## 4. Check-in, break and checkout

- Regular payable coverage starts at the later of actual check-in or scheduled start and stops at the earlier of checkout or scheduled end.
- Late minutes are the relevant difference between scheduled start and actual check-in; do not subtract lateness twice.
- Current regular-break calculation deducts the **greater of allowed break or actual regular break taken**. This means unused assigned break allowance is still deducted.
- Breaks during overtime are deducted from eligible overtime by the billing service; visual overtime elapsed seconds are **not net/payable overtime**.
- Checkout before shift end reduces regular coverage. Check-out after shift end creates potential overtime, pending review.
- Work-session reconciliation/auto-checkout aims to close overdue sessions **two hours after the latest applicable Doctor shift end**. Actual execution depends on Laravel Scheduler. Check database results, not only a `DONE` task log.

## 5. Live VA timer (updated)

- The main VA dashboard ring normally shows scheduled-shift progress, while its original elapsed session counter continues independently.
- As soon as the latest still-open Doctor segment's scheduled end has passed and the VA is still checked in, the ring changes to **amber/orange**, displays **OVERTIME ACTIVE**, and shows a separate live overtime `HH:MM:SS` duration.
- A Doctor-wise panel shows extra elapsed time beyond each Doctor segment's scheduled end in the current session. These figures must **not** be summed across overlapping Doctors to infer unique VA working time.
- The display uses absolute API timestamps, updates every second and reanchors on data refresh. This is a display-only feature; it does not alter attendance, overtime approval, checkout rules or invoices.
- Displayed overtime is bounded by a two-hour indicator, represents elapsed time before break deductions and is never labelled approved/billable until a reviewer approves it.

## 6. Overtime review

- Potential overtime begins **after the Doctor's scheduled end**, excluding applicable overtime breaks.
- Status may be pending, review_required, approved or rejected. Pending and rejected durations are not billable.
- HR/Admin with permission may approve all, approve part or enter authorized custom payment, or reject. Approved minutes cannot exceed eligible recorded overtime.
- Attendance corrections that change recorded overtime may require renewed approval. Count only genuinely reviewable overtime `> 0` in dashboard totals.

## 7. Attendance and schedule change requests

- A VA submits a correction request with a reason; authorized staff review and approve/reject with notes.
- Approved changes should update attendance/schedule and re-evaluate affected regular and overtime values.
- Do not silently rewrite issued/sent/paid invoice data; review editable drafts before reconciling.

## 8. Monthly and manual invoices

- Standard monthly cycle: **26th of one month to 25th of the next**, subject to the configured cycle start day in Invoice Settings.
- Automatic drafts become eligible after the Doctor's final cycle-end shift and applicable open work sessions close; there can be a no-shift fallback.
- Manual generation allows a Doctor, dates, and selected/all eligible VAs.
- **One combined Doctor invoice** contains individually traceable **VA-wise billing rows**, rather than including every employee in the database.
- Only completed, unbilled, **assignment-valid** attendance may be billed. An incorrectly linked V4 attendance cannot enter C1's invoice when V4 was not assigned to C1.
- **First-month billing starts with the first valid check-in for that Doctor–VA pair**, not the employment joining date; never add pre-first-check-in dates as absent/payable simply because Admin selected a wider period.
- Regular amount = eligible payable regular minutes at applicable Doctor–VA rate; overtime charge comes only from **approved** overtime or a valid custom approved amount. Adjustments may increase or decrease totals.
- Do not double bill previously invoiced attendance. Existing drafts with stale VA rows/hours need explicit, reviewed reconciliation rather than silent deletion.

## 9. Invoice PDFs and snapshots

- PDF identity, period, VA-level rows, eligible attendance, amounts and grand total must match invoice generation. The exact column visibility is controlled by the PDF layout.
- Draft invoice details can be updated through authorized workflows. Issued/sent/paid invoices use preserved snapshots and must not silently change following later staff, rate or attendance edits.
- Generator, draft synchronization and frozen PDF snapshot should share the same Doctor–VA attendance eligibility rules.
- Invoice numbers follow the CareVMA Doctor/month convention, subject to collision checks and actual configured generator behavior.

## 10. Finance, records, notifications

- Company Finance separates **billed** amounts, **received** payments, **outgoing** entries, net cash and outstanding balances. Unpaid invoices do not imply received cash.
- Admin/HR Notebook shares a document only with authorized recipients. Employees/Doctors must not see documents addressed to others.
- Notifications must target the actor's relevant counterpart or authorized HR/Admin; email delivery depends on SMTP and queue workers.
- Activity Logs track implemented security/admin events. The combined audit patch disables the prior ten-day pruning job; confirm the installed patch and scheduler state. Already deleted history requires a backup for restoration.

## 11. Probation, compensation and unfinished requirements

- Existing probation fields/reminders do **not by themselves** establish a complete lifecycle with extension, termination and immutable decision history; verify these separately.
- Employee payroll and Doctor billing rates are different. Full non-VA payroll, salary-slip generation, HR approval and distribution are not confirmed as complete.
- Single-company installation: multi-company SaaS isolation is **not in scope** for this project.

## 12. Release and QA safeguards

- Never run destructive migrations or DB resets on live data. Back up before changes; preserve `.env`, `.htaccess` and established deployment configuration.
- Test Doctor timezones (including overnight and DST), break allowance versus actual breaks, two-hour auto-checkout, concurrent schedulers, overtime approval, first-month billing, C1/V1-V4 assignment exclusion, draft-vs-PDF totals and authorization.
- For the updated VA timer specifically: test one Doctor, multiple Doctors with different end times, a break during overtime, a 30-second API refresh, light/dark themes and narrow/mobile screens.
