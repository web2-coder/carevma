# CareVMA Final Replace Patch — 2026-10-28 (Updated)

This replace-ready patch contains the previous consolidated CareVMA changes plus the latest attendance, email, Notebook sharing, and timezone requirements.

## Latest requirements included

1. **Attendance identity display**
   - Admin/HR Attendance now labels the employee as **VA Name**.
   - The assignment identity is shown as **Doctor Worked With**.
   - Attendance date/time calculation remains Doctor/Client-timezone based.

2. **Email delivery / SMTP reliability**
   - Laravel log evidence in the supplied project showed SMTP rejection: `550 5.7.0 Too many emails per second` from Mailtrap.
   - The supplied project `.env` currently points to Titan SMTP, so a live server that still reports Mailtrap is using a different/stale `.env` or cached config.
   - Duplicate operational emails are suppressed for invitation/resend actions.
   - Critical invitation/invoice emails retry transient provider rate-limit errors.
   - Non-critical operational emails are queued into spaced delivery slots.
   - `MAIL_ENCRYPTION=ssl` remains compatible with Laravel 12 mail scheme handling.
   - A non-secret diagnostics command is included: `php artisan carevma:mail-diagnostics`.

3. **Notebook security and document sharing**
   - Notebook management is restricted to **Admin / HR** at both frontend and backend.
   - Admin/HR must choose one exact recipient: a **VA** or **Doctor**.
   - New shared files are stored on the private local disk.
   - VA/Doctor portals now have **Shared Documents**, not Notebook.
   - Recipients can only list/download files whose `recipient_user_id` matches their own user account.
   - Admin/HR can review and delete shared documents.

4. **VA timezone during assignment**
   - The VA's own assigned/profile timezone is shown while selecting/assigning a VA.
   - The Doctor timezone is shown separately and remains the canonical calculation/display timezone for the schedule.

5. **Doctor-side timezone consistency**
   - Doctor dashboard schedules display Doctor local time.
   - Doctor attendance displays Doctor-local dates and clock times.
   - Invoice periods and attendance selection use Doctor/Client-local UTC boundaries.
   - Invoice PDF attendance rows and generated date are rendered using the Doctor/Client timezone.

## Previous consolidated requirements retained

The patch still includes the earlier requirements: Doctor-time system-wide display, one-day date correction, 26th–25th invoice cycle, post-20th billing month assignment, Doctor break visibility, 10-row pagination, session/CSRF fixes, invoice layout/bank page, HR invitation, invoice delete, Doctor type/service on invoice, send invoice PDF, VA shift/break/overtime billing rules, auto-checkout after two hours, Secure/red-dot removal, single assignment notification, recipient-scoped notifications, Poppins/color scheme, birthday popup/banner/cake stickers, and Currently Active cards.

## Apply

Extract this ZIP into the **CareVMA project root** and overwrite/replace matching files.

Then run:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
php artisan storage:link
php artisan carevma:mail-diagnostics
npm install
npm run build
```

For queued operational email notifications, keep a queue worker running:

```bash
php artisan queue:work --tries=3 --sleep=1
```

On shared hosting, run a cron every minute if a persistent worker is unavailable:

```cron
* * * * * cd /path/to/carevma && php artisan queue:work --stop-when-empty --tries=3 --sleep=1 >> /dev/null 2>&1
```

The attendance auto-checkout and scheduled invoice/birthday jobs also require Laravel Scheduler:

```cron
* * * * * cd /path/to/carevma && php artisan schedule:run >> /dev/null 2>&1
```

## Important SMTP deployment check

If the live portal still reports Mailtrap after this patch, run:

```bash
php artisan optimize:clear
php artisan queue:restart
php artisan carevma:mail-diagnostics
```

If diagnostics still show a Mailtrap host, update the **live server's** `.env` to the intended production SMTP account. This patch intentionally does not include or overwrite `.env`, SMTP passwords, or any secrets.

## Safety

The replace ZIP excludes `.env`, `vendor`, `node_modules`, storage logs, cached config, compiled frontend build files, and credentials.
