CareVMA Combined Patch - 2026-10-27
====================================

Includes
--------
1) Client / Doctor onboarding
   - Admin no longer creates or knows the Client/Doctor password.
   - A random unusable account password is created internally.
   - Client/Doctor receives the existing signed CareVMA set-password email.
   - Existing password_reset_tokens + signed route flow is reused.

2) Email recipient rules
   - Admin/superadmin receive operational activity emails globally.
   - HR, VA and Client/Doctor receive operational emails only when they are related to the action.
   - New VA receives its dedicated activation email once; generic creation email to that VA is suppressed.
   - New Client/Doctor receives its dedicated set-password email once; generic creation email to that Client is suppressed.
   - Admin still receives the operational copy of account creation/activity.

3) Login UI
   - Left side uses /VMA-Hero_Image.jpg with a branded overlay.
   - Improved visual hierarchy/card/background while preserving responsive mobile behavior.

4) Dashboard charts
   - Attendance and finance chart colors are normalized to CareVMA green/navy brand colors.

5) Top-right account dropdown
   - Profile image/name opens a dropdown.
   - Profile and Settings actions are role-aware.
   - Logout is the final separated action.

6) Session/CSRF production correction
   - Removes the /sanctum/csrf-cookie call because this project does not register that route.
   - Uses the CSRF meta token rendered by resources/views/spa.blade.php with Laravel web/session middleware.

7) Production asset routing
   - Includes the root .htaccess fix that maps /build/* to public/build/* to prevent the production white screen.

Files replaced by this patch
----------------------------
.htaccess
app/Http/Controllers/Api/AdminApiController.php
app/Http/Middleware/OperationalActivityNotifier.php
app/Services/OperationalNotifier.php
resources/css/app.css
resources/js/api.js
resources/js/context/AuthContext.jsx
resources/js/components/AppShell.jsx
resources/js/pages/Login.jsx
resources/js/pages/Management.jsx

Apply on your LOCAL Windows project
-----------------------------------
1. Back up/commit your current work.
2. Copy the patch folders/files over the project root, preserving paths.
3. Run:

   cd C:\xampp\htdocs\carevmalive
   npm run build
   git status
   git add .htaccess
   git add app/Http/Controllers/Api/AdminApiController.php
   git add app/Http/Middleware/OperationalActivityNotifier.php
   git add app/Services/OperationalNotifier.php
   git add resources/css/app.css
   git add resources/js/api.js
   git add resources/js/context/AuthContext.jsx
   git add resources/js/components/AppShell.jsx
   git add resources/js/pages/Login.jsx
   git add resources/js/pages/Management.jsx
   git add -f public/build
   git commit -m "Add CareVMA onboarding email UI and profile patch"
   git pull --rebase origin main
   git push origin main

4. After Hostinger auto-deploy:

   cd /home/u671369435/domains/carevma.com/public_html/app
   php artisan optimize:clear
   php artisan config:cache
   cat public/build/manifest.json
   ls -lah public/build/assets

5. Test the JS filename shown by manifest:

   curl -I https://app.carevma.com/build/assets/NEW_JS_FILENAME.js

   It MUST return HTTP 200, not 404.

6. Clear app.carevma.com browser site data once, then test in Incognito.

QA notes
--------
- Modified PHP files pass php -l syntax validation.
- The uploaded node_modules came from Windows and lacks Rollup's Linux optional native package, so the Linux sandbox could not complete Vite build. Run npm run build on your Windows project as shown above.
- No database migration is required for this patch.
- Do not commit .env. The patch does not include .env.
