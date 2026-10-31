CareVMA Invoice Filters + Live Workforce Layout Patch

Changes:
1. Invoice list filters: month, year, custom date range, Doctor/Client, Invoice #, status.
2. Filters are applied server-side and work across pagination.
3. Send PDF action is icon-only with Send PDF hover/title tooltip.
4. Live Workforce Online, Checked In, On Break, Late, Offline tabs reuse the same compact card renderer/layout.
5. Preserves latest runtime online status logic from the combined patch.
6. No invoice/attendance calculation logic changed.

Files:
- resources/js/pages/Management.jsx
- app/Http/Controllers/Api/AdminApiController.php

After copying patch files locally run: npm run build
Then commit generated public/build assets together with these source files.
