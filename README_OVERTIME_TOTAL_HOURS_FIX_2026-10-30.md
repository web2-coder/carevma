# CareVMA - Scheduled Total Hours + Approved Overtime Fix

## Changes
- Present attendance PDF rows now show Total Hours as full scheduled shift minus actual break taken.
- Unused break allowance no longer reduces regular payable time.
- Regular work remains capped at the scheduled shift window.
- Work after the scheduled shift end remains overtime.
- Overtime is added to invoice payable hours/amount only via approved_overtime_minutes after HR/Admin approval.

## Example
Shift 3:40 PM-5:00 PM = 80 minutes.
- Actual break 0m => Total Hours 1h 20m.
- Clock in 3:47 PM, clock out 5:00 PM => regular payable 73m.
- Clock out 5:20 PM => overtime 20m (minus any break inside the OT window).
- Until HR/Admin approves OT, payable excludes those OT minutes.
- Once approved, approved OT is included in invoice payable hours and amount.

No migration required. No frontend build required.
