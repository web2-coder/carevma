CareVMA calculated demo reseeder

- Removes ONLY prior seed.va1..5@carevma-seed.test and seed.doctor1..5@carevma-seed.test users first. Their linked demo records are removed by existing FK cascades.
- Recreates 5 VAs and 5 Doctors.
- Creates repeating Mon-Fri schedules with 9-hour spans and 1-hour allowed break (8-hour max regular billable time).
- Randomizes only clock-in, clock-out, break duration, absence, and overtime approval state.
- AttendanceBillingService calculates late_minutes, total_minutes and overtime from timestamps.
- Late arrival/early leave reduces regular payable time.
- Early arrival does not increase regular payable time.
- Regular payable time is capped at scheduled span minus allowed break.
- Time after shift becomes overtime; only approved overtime is additionally payable.

Run:
php artisan db:seed --class=CareVmaRandomAttendanceSeeder
