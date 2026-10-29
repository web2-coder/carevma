CareVMA Resend Blank Screen Fix - 2026-10-28

Replace only:
resources/js/pages/Management.jsx

Fix:
- Employee Resend Activation no longer toggles the page-wide busy/loading state.
- Keeps Employees dashboard rendered while the POST request runs.
- Safely reads activation_url / invitation_sent from the API response.
- Shows the generated activation link card even when SMTP delivery fails.
- Does not change SMTP, backend routes, database, sessions, or other project files.

After replacing locally:
  npm run build
  git add resources/js/pages/Management.jsx public/build
  git commit -m "Fix resend activation blank screen"
  git push origin main

On live server:
  cd /home/u671369435/domains/carevma.com/public_html/app
  git pull origin main
  php artisan view:clear
