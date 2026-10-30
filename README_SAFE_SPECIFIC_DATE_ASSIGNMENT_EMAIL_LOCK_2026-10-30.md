# CareVMA Safe Cumulative Patch - 2026-10-30

This patch is cumulative. It includes the earlier invoice-500/searchable Doctors-Clients dropdown hardening plus the new changes below.

## New changes

1. VA self-service email lock
   - `/employee/profile` displays Email as read-only.
   - Employee profile update no longer accepts or writes email.
   - Login/user email identity remains Admin-managed.

2. Specific-date VA assignment
   - `/admin/client/{clientId}/assign-employee` now has two assignment modes:
     - Weekly
     - Specific Dates
   - Admin can add multiple exact dates in one action (example: 2026-10-15 and 2026-10-24).
   - Each exact-date row has its own start/end time in the Doctor timezone.
   - Existing `employee_client_schedules.schedule_date` is reused. No new migration is required.

3. Invoice/date eligibility
   - InvoiceGenerator now filters attendance to the VA's actual Doctor assignment coverage.
   - A one-time `schedule_date` is billable only on that exact Doctor-local date.
   - Recurring schedules remain billable on configured weekdays.
   - If a VA has only one-time schedules for a Doctor, the invoice PDF attendance table shows only those assigned dates (plus matching attendance), rather than rendering the rest of the cycle as OFF/ABSENT.

## Example

If VA Ali is assigned to Doctor X only on 15 Oct and 24 Oct:
- 15 Oct and 24 Oct are the only scheduled dates for that Doctor.
- Other days in the invoice cycle are not treated as absences for this one-time assignment.
- Only eligible attendance from those assigned dates is included in the Doctor invoice calculation.

## Files in this cumulative patch

- app/Http/Controllers/Api/AdminApiController.php
- app/Http/Controllers/Api/EmployeeApiController.php
- app/Services/InvoiceGenerator.php
- resources/js/components/ui.jsx
- resources/js/pages/Management.jsx
- resources/js/pages/Employee.jsx
- resources/js/pages/ClientProfile.jsx
- resources/css/carevma-professional-theme.css
- resources/css/client-profile.css
- resources/views/invoices/invoice.blade.php

## Deployment

After extracting over the project root on Windows:

    npm run build
    php artisan optimize:clear

Then commit source plus generated `public/build` assets and push.

No database-destructive commands are required. No new migration is included in this patch.
