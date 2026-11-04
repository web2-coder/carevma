# CareVMA Final Shift Total / Overtime / Payable Logic Patch

Final rule requested on 2026-10-30:

- Total Hours = scheduled shift duration minus assigned break allowance.
- Example: 5:00 PM-7:00 PM = 120m, assigned break 30m => Total Hours 1h 30m.
- Time after the scheduled shift end is Overtime.
- Example: checkout 7:25 PM => Overtime 25m.
- Overtime is NOT added to Payable Hours while pending/rejected/review-required.
- After HR/Admin approval, approved overtime minutes are added to Payable Hours and invoice amount.
- Example: regular payable 1h 30m + approved OT 25m => Payable Hours 1h 55m.
- If the VA starts late or leaves before shift end, regular payable remains based on actual covered in-shift time, with the assigned break allowance deducted, and cannot exceed the scheduled net shift duration.
- If actual regular break exceeds the assigned break allowance, the longer actual break is deducted.
- Overtime breaks continue to be deducted from overtime.

Files:
- app/Services/AttendanceBillingService.php
- resources/views/invoices/invoice.blade.php

No migration required. No frontend build required.
