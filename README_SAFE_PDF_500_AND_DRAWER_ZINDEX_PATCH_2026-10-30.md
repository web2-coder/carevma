# CareVMA Safe PDF 500 + Schedule Drawer z-index Patch

Date: 2026-10-30

## Fixes

1. Invoice PDF generation hardened for live/legacy database schema differences.
   - invoice_adjustments is loaded only when the table/link column exists.
   - client_employee_rates is queried only when the table exists.
   - employee_salaries relation is loaded only when invoice_id exists.
   - missing/orphan employee rows no longer crash the invoice Blade view.
   - one-time schedule fields are read with null-safe data_get() access.

2. Client schedule drawer overlay is forced above the rest of the UI:

   .client-schedule-drawer-backdrop {
       z-index: 1001 !important;
   }

## Files

- app/Services/InvoicePdfService.php
- resources/views/invoices/invoice.blade.php
- resources/css/client-profile.css
- resources/css/carevma-professional-theme.css

## Deployment

Apply/overwrite these files in the CareVMA project, then on local Windows run:

npm run build

Commit the source files plus generated public/build assets, push main, then pull on live.
No migration is required for this patch.
