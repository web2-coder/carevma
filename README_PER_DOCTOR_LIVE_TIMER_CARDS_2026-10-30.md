# CareVMA Safe Per-Doctor Live Timer Cards Patch — 2026-10-30

Base: CAREVMA_SAFE_MULTI_DOCTOR_LIVE_DISPLAY_FIX_2026-10-30.zip

## Changes
- VA dashboard keeps one primary VA timezone clock and one overall VA work-session timer.
- Replaces the mixed single Doctor live summary with one separate live card per active Doctor.
- Each Doctor card shows Doctor name, timezone, live Doctor clock, Doctor-local shift, VA-local converted shift, late minutes, and an independent Doctor segment timer.
- Doctor timer is grounded in the backend Attendance segment `clock_in` timestamp and `elapsed_seconds`; later Doctor segments do not inherit the original VA session duration.
- Refresh-safe timer behavior: backend elapsed value anchors the timer and the browser only advances from that anchor.
- Admin Live Workforce now shows an independent live timer on each active Doctor in the VA card and expanded details.
- Checked-in VA count remains one regardless of number of active Doctors.
- No migration and no destructive database change.

## Files
- app/Http/Controllers/Api/EmployeeApiController.php
- app/Http/Controllers/Api/AdminApiController.php
- resources/js/pages/Employee.jsx
- resources/js/pages/Management.jsx
- resources/css/live-workforce.css

## Deploy
Run `npm run build` on the local Windows project after applying this patch, add `public/build`, commit and push. On live, pull and clear/cache Laravel config/routes/views and restart the queue.
