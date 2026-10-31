# CareVMA Safe Multi-Doctor Live Display Fix — 2026-10-30

## Fixes
- Employee navbar clock always uses the VA's own saved timezone.
- Doctor timezones are never promoted to the VA's primary navbar clock.
- VA active-session Doctor cards show Doctor-local shift time and converted VA-local shift time.
- Admin Live Workforce returns and renders every simultaneously active Doctor segment separately.
- Each active Doctor keeps its own timezone, shift label, and late value.
- One VA remains one checked-in VA; multiple Doctor segments do not inflate the checked-in count.

## Important timezone behavior
3:40 PM America/New_York and 3:42 PM America/Chicago are not simultaneous instants. On 2026-10-30, Chicago is one hour behind New York, so 3:42 PM Chicago is 4:42 PM New York. The Chicago Doctor correctly remains `upcoming` until that actual UTC instant.

## Deployment
Run `npm run build` locally on Windows before pushing `public/build`.
No migration is required.
