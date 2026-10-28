CareVMA Activation + Profile Patch - 2026-10-27

This patch is intended to be applied AFTER CareVMA_Combined_Patch_2026-10-27.

Included:
1. Client/Doctor secure password setup invitation remains on creation.
2. Client/Doctor list now has a Resend password setup email action.
3. VA creation continues to send one activation email; employee resend action remains available.
4. Admin and HR can upload a profile photo from My Account.
5. Admin/HR photo is stored on users.profile_picture and shown in sidebar/top-right profile menu.
6. Attendance chart keeps the CareVMA green/navy branded palette from the combined patch.

Database:
Run: php artisan migrate --force
This adds nullable users.profile_picture only if the column does not already exist.

IMPORTANT MAIL NOTE:
Both Client and VA invitation code uses Laravel Mail::send synchronously. If invitation_sent=false, the application reached the mail send call but SMTP delivery failed. Check production MAIL_* settings and storage/logs/laravel.log. After correcting SMTP, use the resend action instead of recreating the account.

Deploy locally:
npm run build
git add .
git add -f public/build
git commit -m "Fix CareVMA activation email resend and admin HR profile photos"
git push origin main

Live server:
cd /home/u671369435/domains/carevma.com/public_html/app
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

Mail diagnostics (do not paste passwords publicly):
php artisan config:show mail

tail -n 100 storage/logs/laravel.log
