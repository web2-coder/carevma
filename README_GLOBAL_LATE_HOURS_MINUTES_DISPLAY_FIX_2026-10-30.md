# CareVMA - Global Late Duration Display Fix

Late durations are now formatted consistently as hours/minutes instead of raw minute counts.

Examples:
- 0 -> 0m
- 45 -> 45m
- 60 -> 1h
- 102 -> 1h 42m
- 165 -> 2h 45m

Updated display areas include:
- VA dashboard overall Late stat
- VA multi-doctor shift list
- VA per-Doctor live cards
- Admin Live Workforce doctor segments
- Admin Live Workforce late-arrival detail
- Admin Attendance Late column
- Client/Doctor attendance Late column

This is display-only. Late calculations and stored values are unchanged.
No database migration is required.
Frontend files changed, so run `npm run build` locally and commit `public/build`.
