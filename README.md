# CareVMA Invoice PDF Adjustment Visibility Fix

This patch only fixes invoice adjustment visibility in generated PDFs.

- Reloads the latest invoice + adjustments before PDF render.
- Disables PDF browser/proxy caching so an edited invoice never shows an old PDF.
- Shows Bonus / Allowance / Reimbursement / Deduction in a dedicated INVOICE ADJUSTMENTS table.
- Shows related VA, type, category, note, +/- amount, net adjustments and final TOTAL COST.
- Adds a cache-busting query value to the View PDF link.
- Does not change attendance, billing-period, timezone, or salary calculation logic.
