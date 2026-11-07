CareVMA - Fresh talha.developer merge - 2026-11-05

Base:
- User-provided carevma-talha.developer.zip (working/current branch snapshot)

Merged invoice changes:
- Configurable cycle start day accepts 1 through 30.
- Short months safely clamp the boundary (for example, February when day 29/30 is selected).
- Automatic generation is Doctor-specific and waits for the Doctor's final enabled VA shift on the cycle-end date.
- Open cycle-end attendance blocks that Doctor's invoice until checkout.
- Configurable fallback-time field is removed from Invoice Settings UI/API validation.
- When no enabled cycle-end shift exists, the non-configurable safe release boundary is 00:00 on the next day in that Doctor timezone.
- Dashboard eye icon-only action restored beside Settings; opens Doctor-wise run details.
- Dashboard moves focus to next cycle after generated cycle while retaining last-run summary.
- Bulk Invoice uses invoice month -> server-side configured billing cycle mapping.
- Invoice month is the month in which the configured cycle ends.
- Cycle preview supports 1-30 and short months.
- Existing invoices are not rewritten.
- Manual custom date-range invoice generation remains available.
- Invoice settings tests updated for 1-30 behavior.

Safety:
- .env and .htaccess were not added or modified by this merge.
- PHP lint passed for 178 PHP files in app/routes/database/tests.
- The supplied source ZIP did not include vendor/, so artisan runtime tests could not run in the audit container.
- npm dependency installation timed out in the audit container; run npm ci (or npm install) and npm run build locally before deployment.
