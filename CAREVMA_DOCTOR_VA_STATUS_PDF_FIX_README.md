# CareVMA Doctor VA Status + Invoice PDF Label Fix

## Doctor portal
- Assigned VA cards now show Online/Offline based on `last_seen_at` (online = activity in the last 5 minutes).
- Shows VA saved profile location.
- Shows latest attendance status: Not checked in / Checked in / Checked out.
- Shows latest check-in and check-out in the Doctor timezone.
- Shows last active time in the Doctor timezone.
- Doctor dashboard refreshes automatically every 30 seconds.
- VA attendance detail page adds a VA Status card and a VA Location table column.

Note: Location is the VA's saved profile location. The current CareVMA attendance schema does not capture browser/GPS coordinates at check-in/out.

## Invoice PDF
- Removed the literal text `(Doctor / Client timezone)` from the attendance section.
- The actual timezone value is still shown.

## Deploy
After replacing these files, rebuild the frontend:

    php artisan view:clear
    npm ci
    npm run build

Then clear/cache Laravel configuration as appropriate for the live server.
