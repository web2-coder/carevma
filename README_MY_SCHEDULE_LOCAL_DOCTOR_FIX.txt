CareVMA - VA Local Schedule Input + Doctor Time Conversion Fix

Behavior after patch:
- Client Profile -> Assign VA / Add Schedule: Start/End are entered in the selected VA's local timezone.
- Backend converts that exact instant to the Client/Doctor timezone before saving the canonical schedule.
- My Schedule shows VA local time first and Doctor local time as the converted view.
- Check-in eligibility, late/overtime and invoice/billing continue to use Doctor/Client canonical timezone.
- DST is handled through IANA timezones such as America/New_York and Asia/Karachi.

IMPORTANT FOR EXISTING SCHEDULES CREATED BEFORE THIS PATCH:
Open the schedule once, confirm the VA-local start/end values, and click Save schedule. That re-saves the row with the corrected Doctor-local canonical time.

Apply:
php artisan optimize:clear
npm run build
php artisan serve
Then Ctrl+Shift+R.
