# CareVMA Final Consolidated Replace Patch — 2026-10-28

This ZIP contains only the files that were changed or added for the final consolidated CareVMA requirements. The folders inside the ZIP match the Laravel project structure.

## How to apply

1. Back up the current CareVMA project and database.
2. Extract this ZIP directly into the CareVMA project root (the folder containing `artisan`).
3. Allow the extracted files to overwrite files with the same names.
4. Do **not** delete unrelated existing project files.
5. Run:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
npm install
npm run build
```

For local development you can run the scheduler with:

```bash
php artisan schedule:work
```

For production, Laravel's scheduler must run every minute (for the 26th invoice cycle, birthday reminders, and the automatic checkout rule). Example cron:

```cron
* * * * * cd /path/to/carevma && php artisan schedule:run >> /dev/null 2>&1
```

HR invitation emails and invoice-PDF emails require working `MAIL_*` settings in your existing `.env`.

## Included requirements

1. VA/VMA operational time uses the assigned Doctor/Client timezone.
2. Date-only formatting avoids the one-day timezone shift.
3. Doctor timezone is the canonical operational display timezone across the portal.
4. Notebook document upload, listing, download/open, and delete support.
5. Invoices generated after the 20th are assigned to the next billing month.
6. Invoice attendance cycle is the 26th through the 25th.
7. Doctor break allowance is visible with schedules.
8. Table pagination is standardized to 10 records per page.
9. Login/logout CSRF/token recovery is hardened; no standalone hardcoded `R` source marker was found.
10. Invoice Grand Total is at the end and bank/payment details are on a dedicated final page.
11. New HR accounts receive a password-setup invitation email.
12. Admin can delete invoices.
13. Doctor service and doctor/contract type are shown on the invoice.
14. Admin can send the invoice PDF directly to the Doctor/Client email.
15. Regular billing is capped at scheduled working time excluding the scheduled break; early clock-in/late clock-out do not increase regular billing. Extra time becomes overtime, must be requested by the VA and approved before billing. Open sessions auto-checkout at Doctor-local shift end + 2 hours.
16. The top-bar Secure section is removed.
17. The top-bar timer red-dot indicator is removed.
18. Weekly/multi-day VA assignment uses one assignment action and sends one VA notification.
19. Operational notifications are scoped to the intended recipient and are not echoed to the admin/actor or unrelated users.
20. Portal brand styling follows the CareVMA sidebar/logo blue/cyan palette and Poppins is enforced across portal surfaces.
21. Birthday popup is shown only to the birthday person and only once per birthday/day, including after logout/login.
22. Birthday dashboard banner stays visible during the birthday day; VAs can see today's birthdays, send one cake sticker per person, and the birthday person can see sticker senders (view-only).
23. Currently Active users are displayed as cards with full-profile links and Admin edit access.

## New database changes

The patch adds migrations for:
- `invoices.billing_cycle_month`
- `notebook_documents`
- `birthday_wishes`
- `users.birthday_popup_seen_date`

## Important

- `.env` is intentionally NOT included.
- `vendor/` is NOT included.
- `node_modules/` is NOT included.
- No passwords, SMTP credentials, application keys, or other secrets are included.
- `CHANGED_FILES.txt` contains the exact replace-file manifest.
