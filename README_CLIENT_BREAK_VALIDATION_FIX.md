# CareVMA Client Break Validation Fix

- Removes `break_allowed_hours` from Client create/update validation.
- Break allowance remains schedule/shift-level only.
- Existing client DB column is left untouched for backward compatibility.
- Schedule validation still requires `break_allowed_hours` in H.MM format (for example `0.30` = 30 minutes).
