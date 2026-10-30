CareVMA PDF Payment Layout + Filename Fix

Changes only:
1. PDF filename format is now:
   carevma-{doctor/client-name}-{invoice-number}.pdf
   Example: carevma-harram-812377.pdf

2. Payment & Bank Details is no longer forced to a new page.
   - If enough room remains on page 1, the whole block stays on page 1.
   - If it cannot fit, DomPDF moves the whole block to the next page.
   - The block is kept together with page-break-inside: avoid.

3. Attendance section still starts on its own page after Payment Details.
4. Existing cleaned invoice/attendance content is preserved.

Replace these files from project root:
- resources/views/invoices/invoice.blade.php
- app/Http/Controllers/Api/InvoicePdfController.php

Then run:
php artisan view:clear
php artisan view:cache

Do not run migrate, key:generate, or optimize:clear.
