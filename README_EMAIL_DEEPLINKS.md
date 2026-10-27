# CareVMA Email Deep-Link Template Patch

This patch changes email presentation/linking only. It does not change billing, attendance, timezone, database, API, or frontend business logic.

## Link behavior
- Team Member / VA account invitation -> existing signed Set Password / activation URL.
- Client / Doctor account -> existing signed Set Password URL.
- Complete Profile -> existing signed profile-completion URL.
- Attendance change request -> Admin Change Requests page with `request_id` context.
- Attendance request approved/rejected -> Employee Attendance page with `attendance_id` + `request_id` context.
- Probation reminder -> Admin Employees page with `employee_id` context.
- Operational notifications -> existing action URL, normalized to an absolute CareVMA URL, with a context-specific button label.
- Invoice -> a ready-to-use `InvoiceNotificationMail` + template with exact invoice detail and PDF links. It is intentionally not wired into a new sending flow, per the requirement to leave all other behavior unchanged.

No migrations are required.
