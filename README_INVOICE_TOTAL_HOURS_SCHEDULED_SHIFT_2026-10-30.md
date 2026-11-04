# CareVMA - Invoice Total Hours scheduled shift fix

This focused patch changes only the Invoice PDF attendance table display.

## Behavior
- `Total Hours` now means the scheduled net shift duration.
- Example: 09:00 AM-06:00 PM = 9 hours gross; allowed break 1 hour; Total Hours = 8h.
- Late arrival or early checkout does not reduce `Total Hours`.
- `Payable Hours` remains the existing actual/approved billing value.
- `Overtime` remains unchanged.
- For an ABSENT scheduled day, `Total Hours` shows the scheduled net shift duration while Payable Hours remains 0m.
- No database migration.
- No invoice amount calculation changes.
