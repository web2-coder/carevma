# CareVMA Route & Permission Matrix

## SPA routes

| Route | Admin | HR | Employee | Client/Doctor |
|---|:---:|:---:|:---:|:---:|
| `/admin/dashboard` | ✓ | — | — | — |
| `/admin/employees` | ✓ | — | — | — |
| `/admin/hr-team` | ✓ | — | — | — |
| `/admin/clients` | ✓ | — | — | — |
| `/admin/schedules` | ✓ | — | — | — |
| `/admin/attendance` | ✓ | — | — | — |
| `/admin/change-requests` | ✓ | — | — | — |
| `/admin/overtime` | ✓ | — | — | — |
| `/admin/invoices` | ✓ | — | — | — |
| `/admin/options/:type` | ✓ | — | — | — |
| `/admin/notifications` | ✓ | — | — | — |
| `/admin/account` | ✓ | — | — | — |
| `/hr/dashboard` | — | ✓ | — | — |
| `/hr/employees` | — | ✓ | — | — |
| `/hr/schedules` | — | ✓ | — | — |
| `/hr/attendance` | — | ✓ | — | — |
| `/hr/change-requests` | — | ✓ | — | — |
| `/hr/overtime` | — | ✓ | — | — |
| `/hr/options/:type` | — | ✓* | — | — |
| `/hr/notifications` | — | ✓ | — | — |
| `/hr/account` | — | ✓ | — | — |
| `/employee/dashboard` | — | — | ✓ | — |
| `/employee/schedule` | — | — | ✓ | — |
| `/employee/attendance` | — | — | ✓ | — |
| `/employee/profile` | — | — | ✓ | — |
| `/employee/settings` | — | — | ✓ | — |
| `/doctor/dashboard` | — | — | — | ✓ |
| `/doctor/employee/:employeeId/attendance` | — | — | — | ✓** |
| `/doctor/invoices` | — | — | — | ✓ |
| `/doctor/profile` | — | — | — | ✓ |

`*` HR cannot modify client services or contract types.  
`**` Backend verifies that the employee is assigned to the authenticated client.

## API authorization

- `/api/management/*`: Admin/Super Admin and HR.
- `/api/admin/*`: Admin/Super Admin only.
- `/api/employee/*`: authenticated Employee only and always resolves the employee from the signed-in user.
- `/api/doctor/*`: authenticated Client/Doctor only and always resolves the client from the signed-in user.
- `/invoices/{invoice}/pdf`: authenticated; server verifies Admin/Super Admin or owning Client before rendering.

Frontend menu visibility is not used as the security boundary. Laravel role middleware and record ownership are enforced server-side.
