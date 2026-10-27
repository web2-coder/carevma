CareVMA Final Doctor/VA Timezone Consistency Patch

Rules:
- Client/Doctor IANA timezone is the schedule/check-in/late/overtime calculation source.
- VA sees the same absolute shift converted to the VA's own timezone.
- Dashboard shows next shift with Doctor time, VA time, Doctor check-in opening, and VA-local check-in opening.
- Check-in button availability is based on the Doctor-local schedule instant, so VA sees it at the correct converted local time.
- Invoice attendance timestamps remain Client/Doctor timezone based.
- DST is handled by IANA timezones such as America/New_York.

Apply at project root, overwrite files, then run:
php artisan optimize:clear
npm run build
php artisan serve
