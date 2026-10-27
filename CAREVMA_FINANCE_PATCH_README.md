# CareVMA - 25th to 26th Billing + Company Finance Patch

## What this patch adds

1. Billing cycle is fixed to **25th -> 26th of the following month**.
2. Automatic draft invoice generation runs on the **27th at 12:15 AM** (CareVMA business timezone), after the full 26th is complete.
3. Overlap protection prevents attendance dates already covered by a non-void invoice from being billed again.
4. New Admin-only **Company Finance** page:
   - URL: `/admin/company-finance`
   - Day-wise / month-wise / year-wise reports
   - Billed revenue
   - Paid invoice income
   - Manual income
   - Expenses / money out
   - Net cash
   - Outstanding invoice amount
   - Income vs outgoing graph
   - Manual income/expense entry management
5. Admin dashboard includes a six-month finance graph and a **View Company Finance** link.
6. Scheduler callback names are included for invoice, birthday and probation jobs so `withoutOverlapping()` does not throw the Laravel LogicException.

## Install

Extract this ZIP into the CareVMA project root and overwrite the included files only.

Then run:

```powershell
php artisan optimize:clear
php artisan migrate
php artisan schedule:list
npm install
npm run build
php artisan serve
```

If frontend dependencies are already installed, `npm install` can be skipped.

## Check

- Admin finance page: `http://127.0.0.1:8000/admin/company-finance`
- Admin dashboard: `http://127.0.0.1:8000/admin/dashboard`
- Invoices: `http://127.0.0.1:8000/admin/invoices`

## Finance calculation

- **Billed revenue:** all non-void invoice totals whose billing period ends inside the selected report period.
- **Money received:** paid invoice totals + manually recorded income.
- **Money out:** manually recorded company expenses.
- **Net cash:** money received - money out.
- **Outstanding:** billed revenue - paid invoice revenue.

Existing invoices are not deleted or reset by this patch.
