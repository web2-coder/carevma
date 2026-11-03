CareVMA Safe Patch - Salary Slips
Date: 2026-11-03

PURPOSE
- Adds the missing Salary Slip Generation + Admin/HR Approval workflow.
- Works for every employee record: VA, SEO, developer, HR-support staff, or any other non-VA employee.
- Keeps existing project structure unchanged.
- Does NOT replace or modify invoice, attendance, overtime, schedule, or doctor-timezone calculation logic.

WHAT IS ADDED
1. New salary_slips database table.
2. Admin + HR Salary Slips page.
3. Employee "My Salary Slips" page (approved slips only).
4. Generate draft salary slip from existing employee PKR/USD salary.
5. Optional bonus / allowance and deduction.
6. Admin/HR approval locks the salary slip.
7. PDF download for Admin/HR and the owning employee.
8. Search/filter payroll register.

FILES IN THIS PATCH
- routes/web.php
- app/Http/Controllers/Api/AdminApiController.php
- app/Http/Controllers/Api/SalarySlipController.php
- app/Models/SalarySlip.php
- database/migrations/2026_11_03_100000_create_salary_slips_table.php
- resources/js/app.jsx
- resources/js/components/AppShell.jsx
- resources/js/pages/SalarySlips.jsx
- resources/views/salary_slips/pdf.blade.php

SAFE DEPLOYMENT
1. Back up the current live project and database.
2. Extract this ZIP over the project root, preserving folders.
3. Run:
   php artisan migrate --force
   php artisan optimize:clear
4. Build frontend in the normal project build environment:
   npm install
   npm run build
5. Upload/deploy the generated public/build if production hosting does not have Node/npm.
6. Login as Admin/HR and open Salary Slips.

NOTES
- Existing salary_pkr / salary_usd is used only as the default base amount. It can be adjusted while the slip is a draft.
- Approved salary slips cannot be edited or deleted by this module.
- Employees only see their own approved salary slips.
- Duplicate slip for the same employee + exact period + currency is prevented by a database unique key.

VALIDATION PERFORMED
- PHP lint passed for all new/modified PHP files.
- JSX syntax parsed successfully for the new/modified React files.
- Full Vite build could not run in the sandbox because the uploaded node_modules contains Windows Rollup native packages, not the Linux native package required by this environment.
- artisan route:list could not run in the sandbox because this PHP runtime is missing mbstring (mb_split).
