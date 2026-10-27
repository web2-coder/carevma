# What Changed

## Frontend

The uploaded project used Blade, jQuery/AJAX and DataTables. The portal has been replaced with a React 19 + React Router SPA. Laravel serves one SPA shell for portal URLs, while React renders pages and performs same-origin Axios requests to `/api`.

The new design includes:

- responsive collapsible dark navigation;
- role-aware menus;
- dashboard KPI cards and activity visualization;
- reusable cards, tables, pagination, badges, forms, notices and modals;
- mobile navigation and table handling;
- explicit loading, empty and error states;
- consistent form validation feedback;
- modern employee, client, attendance, overtime, invoice and configuration workflows.

## Backend

A dedicated JSON API now covers authentication, Admin/HR management, Employee self-service and Doctor/Client read scopes. Role middleware is registered in Laravel 12 and record ownership is resolved from the authenticated account, not posted account IDs.

## HR role

HR is a first-class login role. Admin can create/edit/delete HR accounts from `/admin/hr-team`. HR can manage employees, schedules, attendance, corrections, overtime and HR reference lists but cannot administer clients, invoices, services or contract types.

## Attendance

New attendance sessions retain client/schedule ownership when the upgrade migration has been applied. Employee check-in validates the selected enabled schedule and allows entry starting 15 minutes before the scheduled time. Early time is not treated as regular work; late time and post-shift overtime are calculated at checkout. Break seconds are deducted from regular time.

## Compatibility

Existing Laravel models/tables and historical data are retained. Old portal URL patterns are redirected to current React pages. Existing Google OAuth, signed onboarding and invoice PDF server endpoints are retained because they are integration/document endpoints rather than in-app SPA page navigation.

## Safety

The deliverable does not include the uploaded SQL database dump or production credentials. Demo accounts are opt-in through `CARE_VMA_DEMO_SEED=true` and are disabled by default.
