# CareVMA Invoice Number + 24 Hour Activation Patch

## Invoice number
New invoices use:
`CAREVMA-INV-{DoctorName}-{YYYYMM}`

Example:
`CAREVMA-INV-Harram-Doctor-202610`

If another invoice for the same doctor/month needs a distinct number, suffixes are added (`-02`, `-03`, ...).
Existing invoice numbers are not renamed.

## Activation expiry
New VA/Employee, Doctor/Client and HR activation/password-setup tokens are valid for 24 hours (1440 minutes). Resending generates a fresh token/link and restarts the 24-hour window.

## Deploy
Extract over project root, then:

php artisan config:clear
php artisan route:clear
php artisan view:clear
npm run build

After verifying, config may be cached with:
php artisan config:cache
