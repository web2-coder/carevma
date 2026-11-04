# CareVMA Safe Patch - Global Paginated Search + Shift End Visibility

## 1) Search with pagination
Paginated searches are server-side: the API filters the complete matching dataset first and only then applies 10-per-page pagination. This avoids searching only the 10 records already visible in the browser.

Added/standardized backend search on:
- Schedules
- Attendance correction requests
- Schedule/shift change requests
- Overtime

Existing Employees, Clients, HR, Attendance, Notebook and Shared Documents searches remain server-side.

## 2) Shift end visibility
For every active Doctor segment:
- Admin Dashboard > Currently Working shows Doctor timezone plus `Ends at` and live `Ends in`.
- Admin > Live Workforce shows the same per Doctor.
- VA Dashboard shows `Shift end` independently inside every active Doctor card and in the session agenda.

The countdown is anchored to server time and the Doctor segment UTC end instant. It does not rely on the browser timezone for calculation.

## Deployment
No migration required.
Frontend files changed, so run `npm run build` locally and commit `public/build`.
