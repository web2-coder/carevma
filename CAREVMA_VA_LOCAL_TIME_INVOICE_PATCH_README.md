# CareVMA VA Local Time + Invoice PDF Cleanup Patch

## What changed

1. Doctor/Client timezone remains the source of truth for scheduling, check-in eligibility, attendance, overtime, and invoice calculations.
2. Assigned VA local shift time is shown only as a reference to:
   - the assigned VA (Dashboard + My Schedule)
   - Admin (Schedules)
   - HR (Schedules)
3. Doctor portal is unchanged and does not show VA-local shift conversion.
4. Invoice PDF removes these fields completely:
   - Break Time
   - Work + Break
   - Late (mins)
   - Overtime (mins)
5. Invoice item summary now shows: VA Name, Worked Time, Rate/h, From, To, Amount.
6. Invoice attendance detail now shows: Date, Clock In, Clock Out, Worked Time.

## Install

Extract this ZIP into the CareVMA project root and replace existing files.

Then run:

```bash
php artisan view:clear
npm ci
npm run build
```

On production, rebuild `public/build` because Vite build output is ignored by Git in this project.

## Important

This patch does not change billing calculations, attendance calculations, overtime approval logic, or Doctor-side timezone behavior. It only adds a VA-local reference display for VA/Admin/HR and removes the requested invoice PDF columns.
