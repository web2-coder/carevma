// In-app documentation for the management portal. Each entry describes one module as the
// code actually behaves; keep it in step with the feature it documents. `module` is the
// Roles & Permissions key (null = shown to every staff user), `path` builds the module's
// link from the viewer's base (/admin or /hr); admin-only modules ignore the base.

export const DOCS = [
  {
    key: 'getting-started', group: 'Start here', title: 'How CareVMA fits together', module: null,
    summary: 'CareVMA runs a virtual-assistant (VA) workforce for doctors: who works for whom and when, what was actually worked, what needs approval, and what gets billed.',
    who: 'Everyone on the staff side: Administrators and HR.',
    steps: [
      'Four roles sign in: Administrator (everything), HR (the modules granted on Roles & Permissions), Team Member / VA (their own portal: schedule, attendance, profile) and Client / Doctor (their own portal: assigned VAs, attendance, documents).',
      'Doctors are set up as Clients, VAs as Employees. A Schedule links one VA to one Doctor for given days and hours and carries that pair\'s hourly rate.',
      'VAs clock in and out against their schedules. Attendance is what actually happened; Change Requests and Overtime Review are how exceptions get approved.',
      'Invoices bill a Doctor for a period from completed attendance. Company Finance tracks the money side.',
      'Notifications tell the people involved about each change, and Activity Logs keep a record of who did what.',
    ],
    rules: [
      'Doctor time is canonical. Shifts, attendance and invoices are evaluated in the Doctor\'s timezone; VA-local times are shown alongside for convenience.',
      'Every write to the API is recorded in Activity Logs and raises notifications; reads are not logged.',
      'Sign-in, password-reset and password-change endpoints are rate limited. After too many attempts the form tells you how long to wait.',
    ],
  },
  {
    key: 'dashboard', group: 'Overview', title: 'Dashboard', module: null, path: base => `${base}/dashboard`,
    summary: 'A one-page view of the workforce right now and the billing cycle in progress.',
    who: 'Administrators and HR. Cards for modules you do not hold are hidden.',
    steps: [
      'Metric cards count Employees, Clients, Live employees and Pending overtime (Invoices for admins). Each card opens its module.',
      'Team time zone clocks show the live time in every timezone a VA or Doctor is assigned to.',
      'Automatic monthly invoices shows the cycle that is open, when drafts will be generated, and how many drafts, VAs and dollars the last run produced. The Settings link opens Invoice Settings.',
      'Currently working lists VAs with an open session, who they are working for and when the shift ends.',
      'Attendance activity charts sessions per weekday this week; Recent attendance lists the latest sessions.',
    ],
    rules: [
      'Admins also get birthday reminders here for VAs whose birthday is today or coming up.',
      'Company finance appears on the dashboard only for roles that hold the Company Finance module.',
    ],
  },
  {
    key: 'live-users', group: 'Overview', title: 'Live Workforce', module: 'live-users', path: base => `${base}/live-users`,
    summary: 'Who is checked in, on break, late or offline at this moment, with a running timer per Doctor.',
    who: 'Roles with the Live Workforce module.',
    steps: [
      'The summary cards count Checked In, On Break, Late and Offline VAs. The chips below filter the list.',
      'Each VA card shows the VA and the Doctor(s) in the current work session. Click a card to open the details popup.',
      'The popup shows the work and break timers, late minutes, every Doctor segment with its own timer, and when each shift ends. From there you can open the full profile or edit the user.',
      'The page refreshes itself; status labels update without a reload.',
    ],
    rules: [
      'A VA is "late" when the first clock-in is after the shift start in Doctor time.',
      'Sessions still open two hours after the last shift end are closed automatically at that point.',
    ],
  },
  {
    key: 'employees', group: 'People', title: 'Employees', module: 'employees', path: base => `${base}/employees`,
    summary: 'The VA directory: identities, work details, hierarchy, timezone and compensation, plus each VA\'s schedule and attendance.',
    who: 'Roles with the Employees module.',
    steps: [
      'Add employee opens a six-step wizard: Basic, Work, Hierarchy, Personal, Documents and Timezone. Save sends the VA an invitation email with an activation link; Resend invite sends it again.',
      'Each VA gets an employee code (for example CVMA16) that appears across the app and on invoices.',
      'The profile page has tabs for the overview, the schedule grouped by Doctor, attendance history and documents. Edit opens the same fields as the wizard.',
      'On first sign-in a VA must complete their own profile before the rest of their portal unlocks.',
      'Search and sort the list; column headers sort server-side.',
    ],
    rules: [
      'An employee with schedules, attendance or billing history cannot be deleted. Disable or reassign them instead.',
      'The VA\'s own timezone is used to show local shift times; the Doctor\'s timezone remains the basis for attendance and billing.',
      'First-month Doctor billing starts at the VA’s first valid check-in for that Doctor, not their employment joining date. Previously billed attendance must not be charged again.',
    ],
  },
  {
    key: 'hr-team', group: 'People', title: 'HR Team', module: 'hr-team', path: () => '/admin/hr-team',
    summary: 'Dedicated HR logins for the management portal.',
    who: 'Administrators by default; grant the module to other roles on Roles & Permissions.',
    steps: [
      'Create an HR user with a name and email. They receive a secure email link to set their password.',
      'Edit a name or email, or delete the account, from the row actions.',
      'What HR users can open is controlled centrally on Roles & Permissions, not per user.',
    ],
    rules: ['HR users never see modules they have not been granted, in the sidebar or through the API.'],
  },
  {
    key: 'clients', group: 'People', title: 'Clients (Doctors)', module: 'clients', path: () => '/admin/clients',
    summary: 'Doctor accounts, their timezone, service and contract type, and which VAs work for them at what rate.',
    who: 'Administrators by default; grant the module to other roles on Roles & Permissions.',
    steps: [
      'Add client with name, business name, email, timezone, service, contract type and the default hourly charge. The Doctor receives an email with a signed set-password link.',
      'The client profile has tabs: Profile, Assign VA, Schedule, Daily work report and Timezone.',
      'Assign VA picks a VA, the weekdays or specific dates, shift times, the break allowance and this VA\'s hourly rate for this Doctor. Saving creates the schedules and notifies the VA.',
      'Schedule shows every VA assigned to the Doctor grouped per VA, with the rate and a link to the VA profile.',
      'Daily work report shows the Doctor\'s attendance day by day.',
    ],
    rules: [
      'The per-VA rate on the assignment is what invoices use; the client\'s default hourly charge applies only when no rate exists for that VA.',
      'A client with schedules, attendance, salaries or invoices cannot be deleted.',
      'Changing a client\'s timezone changes how future shifts and attendance are interpreted; it does not rewrite past records.',
    ],
  },
  {
    key: 'schedules', group: 'Time', title: 'Schedules', module: 'schedules', path: base => `${base}/schedules`,
    summary: 'Every VA-to-Doctor shift: weekly recurring days or specific dates, with times, break allowance and rate.',
    who: 'Roles with the Schedules module.',
    steps: [
      'Create a shift for one weekday, or use the batch form to assign several weekdays at once. Specific-date shifts cover one calendar day only.',
      'Set the time basis: times entered in Doctor time (default) or in the VA\'s own time. Either way the Doctor-local shift is what attendance is measured against.',
      'The break allowance (hours) is deducted from regular time whether or not the VA takes it; longer actual breaks deduct the longer amount.',
      'Disable a shift to keep its history but stop check-ins; delete it only if nothing depends on it.',
      'Search by VA, Doctor, date, day or time. A global search result for a schedule lands here with the search prefilled.',
    ],
    rules: [
      'A VA can check in from 15 minutes before a shift starts. Shifts for other Doctors that start while the session is open join it automatically.',
      'VAs can ask for a schedule change from their portal; those requests appear under Change Requests.',
    ],
  },
  {
    key: 'attendance', group: 'Time', title: 'Attendance', module: 'attendance', path: base => `${base}/attendance`,
    summary: 'What was actually worked: one row per VA shift with clock in/out, breaks, lateness, regular minutes and overtime, all in Doctor time.',
    who: 'Roles with the Attendance module.',
    steps: [
      'Filter by VA and date range, or search. Rows link to the VA and the Doctor.',
      'Clock in: a VA may start from 15 minutes before the shift. Regular time runs only inside the shift; the first clock-in after shift start counts as late minutes.',
      'Breaks: the VA starts and ends breaks in their portal, up to the allowance on the schedule. Regular time is reduced by at least the allowance, or by the longer actual break.',
      'Clock out: anything after the shift end is overtime and is held for Overtime Review; it is not billable until approved. Open sessions auto-close two hours after the last shift end.',
      'Corrections are not edited here; they go through Change Requests so the VA, the reviewer and the log agree.',
    ],
    rules: [
      'Total minutes on a row are regular minutes inside the shift, net of break; overtime is kept separately.',
      'Completed, previously billed attendance cannot be billed twice. Corrections must trigger a review of editable drafts, while issued financial records remain protected.',
    ],
  },
  {
    key: 'change-requests', group: 'Time', title: 'Change Requests', module: 'change-requests', path: base => `${base}/change-requests`,
    summary: 'Two review queues: attendance corrections and schedule/shift change requests raised by VAs.',
    who: 'Roles with the Change Requests module.',
    steps: [
      'Attendance correction: the VA picks a shift, proposes a new clock in/out and gives a reason. Review it, optionally override the approved times, add a note, then approve or reject.',
      'Schedule change: the VA asks to move a shift to another date or time. Approve as requested, approve with adjusted date/times, or reject with a note.',
      'Approving rewrites the attendance or schedule and recalculates minutes, lateness and overtime. Rejecting leaves everything as it was.',
      'Times are shown on a 12-hour clock. A notification link opens the page with the exact row highlighted.',
    ],
    rules: [
      'One pending request per attendance record or per shift; a reviewed request cannot be reviewed again.',
      'The VA is notified of the decision and the note.',
    ],
  },
  {
    key: 'overtime', group: 'Time', title: 'Overtime Review', module: 'overtime', path: base => `${base}/overtime`,
    summary: 'Approve, partially approve or reject the minutes a VA worked after a shift ended.',
    who: 'Roles with the Overtime Review module.',
    steps: [
      'The review list includes overtime greater than zero which requires a decision or has an approval history. Zero-minute pending rows must not inflate the dashboard pending count.',
      'Open a row to approve all minutes, approve a smaller number, set a custom payment amount, or reject.',
      'VAs can request a review of their own overtime from their portal; it then shows as pending here.',
    ],
    rules: [
      'Only approved overtime is billable. Invoices count approved minutes (capped at the recorded overtime) and show pending minutes as not yet counted.',
      'If the attendance changes after an approval, the decision goes back for review automatically.',
      'Approve overtime before issuing an invoice. Editable draft figures must be reconciled before finalizing; issued/sent/paid invoices must not silently change.',
    ],
  },
  {
    key: 'invoices', group: 'Billing', title: 'Invoices', module: 'invoices', path: () => '/admin/invoices',
    summary: 'Bill Doctors for completed attendance: automatic monthly drafts, manual drafts for any period or any subset of VAs, editing, PDFs and emailing.',
    who: 'Administrators by default; grant the module to other roles on Roles & Permissions.',
    steps: [
      'Automatic drafts: once a billing cycle closes (see Invoice Settings) a draft is created per Doctor, in the Doctor\'s timezone, for every VA with unbilled attendance.',
      'Generate invoice: select a Doctor, date range and optionally particular VAs. A combined Doctor invoice contains separate VA billing rows. Only completed, unbilled attendance supported by a valid Doctor–VA assignment is eligible.',
      'Bulk actions: choose an invoice month and several Doctors to generate drafts or email PDFs in one go.',
      'Edit: adjust hours, base salary, late minutes and approved overtime per VA, add bonus/allowance/reimbursement/other adjustments as additions or deductions, and move the status draft → issued → paid, or void it.',
      'View PDF presents Doctor and billing details, VA-wise eligible attendance, payable hours, approved overtime, applicable rates and grand total. Send emails it to the Doctor. The configured PDF template determines which intermediate columns are visible.',
    ],
    rules: [
      'Finalized, sent or paid invoices must not be silently recalculated. Correct issued financial records only through an authorized and auditable workflow.',
      'An invoice is filed under the month its period ends in.',
      'Amounts: regular payable minutes times the applicable Doctor–VA billing rate, plus only approved overtime or authorized custom overtime payment. Pending and rejected overtime remain unbilled.',
      'Deleting an invoice removes its salary lines and adjustments; the attendance becomes unbilled again.',
    ],
  },
  {
    key: 'company-finance', group: 'Billing', title: 'Company Finance', module: 'company-finance', path: () => '/admin/company-finance',
    summary: 'The cash view: billed revenue, money received, money out, net cash and what is still outstanding, plus manual income and expense entries.',
    who: 'Administrators by default; grant the module to other roles on Roles & Permissions.',
    steps: [
      'Billed revenue sums all non-void invoices; Money received is paid invoices plus manual income; Money out is manual expenses; Net cash is received minus out; Outstanding is billed but not paid.',
      'Add an entry with a date, type (income or expense), category, amount and description. Entries can be deleted.',
      'The salary converter uses the live PKR/USD rate; if the rate service is unavailable the last cached rate is used.',
    ],
    rules: ['Invoice amounts flow in automatically from the Invoices module; only manual entries are typed here.'],
  },
  {
    key: 'invoice-settings', group: 'Billing', title: 'Invoice Settings', module: 'invoice-settings', path: () => '/admin/invoice-settings',
    summary: 'The monthly billing cycle: which day it starts, when drafts are generated, and whether automatic generation is on.',
    who: 'Administrators by default; grant the module to other roles on Roles & Permissions.',
    steps: [
      'Cycle starts on a day from the 1st to the 28th. A cycle ends the day before that day in the following month; the 1st gives calendar months.',
      'Automatic invoice drafts wait for each Doctor’s final cycle-end VA shift and any open attendance to close.',
      'Switch automatic generation off to create drafts manually only. The dashboard card and the invoices page say when it is off.',
      'The preview shows the last completed cycle, the current one and the next automatic run before you save.',
    ],
    rules: [
      'Changes apply to future runs. Existing invoices keep their periods.',
      'Changing the cycle never double-bills: only attendance not yet on an invoice is picked up.',
    ],
  },
  {
    key: 'notebook', group: 'Documents', title: 'Notebook', module: 'notebook', path: base => `${base}/notebook`,
    summary: 'Share documents with a specific VA or Doctor, who sees them under Documents in their own portal.',
    who: 'Roles with the Notebook module.',
    steps: [
      'Upload a file (PDF, Word, Excel, CSV, text or image, up to 10 MB) with a title, optional notes and the recipient.',
      'The recipient can read and download it from their Documents page; you can download or delete it here.',
    ],
    rules: ['A document is visible only to its recipient and to staff with the Notebook module.'],
  },
  {
    key: 'activity-logs', group: 'Logs', title: 'Activity Logs', module: 'activity-logs', path: () => '/admin/activity-logs',
    summary: 'Audit history of recorded authentication and administrative events; the exact event coverage depends on the implemented logging hooks.',
    who: 'Administrators by default; grant the module to other roles on Roles & Permissions.',
    steps: [
      'Each row shows when, who (name, email, role), the action, the module, the affected user, the request method and path, and the IP address.',
      'Filter by user, module, role or date range, or search across action, description, names, path and IP.',
      'Successful and failed sign-ins and sign-outs are logged as well as every create, update and delete.',
    ],
    rules: ['The updated retention patch disables the prior automatic 10-day pruning. Confirm scheduler configuration and backups; data already pruned cannot be recovered without a backup.'],
  },
  {
    key: 'configuration', group: 'Setup', title: 'Configuration', module: 'configuration', path: base => `${base}/options/departments`,
    summary: 'The reference lists used across forms: departments, roles, employment types, shift types, designations, employee status, expertise and reporting managers; admins also manage services and contract types.',
    who: 'Roles with the Configuration module (HR sees the HR lists; services and contract types are admin-only).',
    steps: [
      'Pick a list from the Configuration menu, then add, rename or delete items.',
      'Items appear immediately in the employee and client forms.',
    ],
    rules: ['An item that is in use by an employee or client cannot be deleted.'],
  },
  {
    key: 'roles-permissions', group: 'Setup', title: 'Roles & Permissions', module: null, adminOnly: true, path: () => '/admin/roles-permissions',
    summary: 'Decide which modules each staff role can open, in one matrix.',
    who: 'Administrators only.',
    steps: [
      'Rows are modules, grouped as in the sidebar. The Administrator column is always on; tick or untick modules in the HR column and save that column.',
      'A granted module appears in that role\'s sidebar and its pages and API open; a revoked one disappears and returns "no access" from the API.',
      'Signed-in users of the role pick up the change on their next page load.',
    ],
    rules: [
      'Access is per module, not per action: a role with Invoices can view, generate, edit and delete them.',
      'Any new staff role that appears in the users table is listed automatically.',
    ],
  },
  {
    key: 'notifications', group: 'Account', title: 'Notifications', module: null, path: base => `${base}/notifications`,
    summary: 'Who is told what, and how notification links behave.',
    who: 'Everyone.',
    steps: [
      'The bell shows unread notifications; Mark all read clears them without asking. The Notifications page lists everything.',
      'Each notification links to the exact record: a table page opens with that row highlighted and scrolled into view; a profile change opens that person\'s profile.',
      'People directly affected by an action (for example the VA whose overtime was reviewed) are notified in-app and by email. Admins and HR receive an in-app audit copy of every change made by others, without email.',
    ],
    rules: ['Notifications are never sent back to the person who performed the action.'],
  },
  {
    key: 'account', group: 'Account', title: 'My Account & security', module: null, path: base => `${base}/account`,
    summary: 'Your own name, email, photo and password, and the sign-in protections that apply to every account.',
    who: 'Everyone.',
    steps: [
      'Update your name, email and profile photo, or change your password after confirming the current one.',
      'Forgot password sends a reset link by email; VA activation uses the same reset flow.',
    ],
    rules: [
      'Sign-in allows five attempts a minute and twenty an hour per account from one place, and thirty a minute per network address across all accounts; the form shows how long to wait.',
      'Password changes and resets are limited to six attempts a minute.',
    ],
  },

  {
    key: 'va-live-timer', group: 'Time', title: 'VA live timer and overtime display', module: null,
    summary: 'The VA dashboard separates scheduled shift progress, elapsed work-session time and live overtime. Overtime changes the circular indicator to amber.',
    who: 'Administrators and HR can use these rules to explain the VA dashboard; the live timer itself is on the VA portal.',
    steps: [
      'During a Doctor shift the ring is the normal primary color and shows scheduled shift progress; the main counter continues to show elapsed session time.',
      'When the latest still-open Doctor shift end is reached and the session remains open, the ring turns amber/orange and displays a live overtime timer in HH:MM:SS.',
      'A separate overtime section displays elapsed overtime for this work session by Doctor. Each Doctor has its own shift-end boundary.',
      'Doctor times are calculated from the API ISO timestamps, not from the browser timezone; the display updates every second and reanchors on periodic refresh.',
      'Break timers remain separate. The live overtime display is elapsed after shift end, before overtime-break deductions or HR review.',
    ],
    rules: [
      'The live overtime timer is informational only: it is not an approved, paid or invoiceable amount.',
      'Auto-checkout is designed for two hours after the latest applicable shift end; the displayed overtime window is capped at 02:00:00.',
      'When multiple Doctors overlap, Doctor-specific overtime durations are not added together to represent total unique VA work time.',
      'Offline tabs and background throttling can delay visual ticks; the server remains authoritative for final attendance.',
    ],
  },
  {
    key: 'va-portal-guide', group: 'Role guides', title: 'Employee / VA portal', module: null,
    summary: 'A VA sees personal schedules, a single work session, breaks, attendance, overtime review requests, shared documents and account settings.',
    who: 'Employee / VA (documented here for Admin/HR).',
    steps: [
      'Open My Schedule to see Doctor and VA-local shift times and the date when check-in becomes available.',
      'Check in during the allowed window, up to 15 minutes before Doctor shift start. Regular payable time never begins before scheduled start.',
      'Use Start break and End break to log break time; choose Clock out when finished. A single session can cover multiple Doctors, each with its own attendance segment.',
      'View regular shift progress and, after shift end, the amber overtime ring and separate live overtime figures.',
      'Review My Attendance and submit a Change Request for a mistaken time. Request Overtime Review for eligible recorded overtime.',
      'Update My Profile and My Settings; view only documents directly shared with this account.',
    ],
    rules: [
      'The VA does not approve their own overtime or edit finalized invoices.',
      'The Doctor timezone determines attendance boundaries; VA-local times are display conversions.',
      'Live time and billable time differ because early arrival, breaks, overtime caps and approvals apply.',
    ],
  },
  {
    key: 'doctor-portal-guide', group: 'Role guides', title: 'Doctor / Client portal', module: null,
    summary: 'Doctors see assigned VAs, Doctor-local shift/attendance information, invoices and specifically shared documents.',
    who: 'Doctor / Client (documented here for Admin/HR).',
    steps: [
      'Sign in through the authorized client account and review assigned VAs and applicable schedules.',
      'Inspect attendance belonging to this Doctor; unrelated client or VA history must not appear.',
      'Review Doctor invoices, including separate VA billing rows on the combined client invoice, when available.',
      'Download authorized invoice PDFs and shared documents.',
    ],
    rules: [
      'All Doctor-facing times are shown in the Doctor timezone.',
      'Only attendance validated for the Doctor–VA relationship can be billed.',
      'Invoice detail and grand total must reconcile to the same validated attendance and approved overtime.',
    ],
  },
  {
    key: 'billing-integrity', group: 'Billing', title: 'Invoice safeguards and PDF snapshots', module: 'invoices', path: () => '/admin/invoices',
    summary: 'Historical assignment validation, first-month billing, editable draft reconciliation, approved-only overtime and immutable finalized PDF data.',
    who: 'Administrators and authorized invoice reviewers.',
    steps: [
      'Monthly cycles normally cover the 26th through the 25th; the configured start day in Invoice Settings controls future cycles.',
      'Automatic draft generation waits until each Doctor’s final cycle-end VA shift and relevant open work sessions are complete.',
      'First-month billing begins at the first valid check-in for that VA–Doctor pair, even when Admin selects an earlier date range.',
      'The invoice generator and PDF snapshot must select the same assignment-valid, completed attendance; exclude unrelated or incorrectly linked VA rows.',
      'Before finalizing, review stale drafts for invalid employees, hours or rates. Use a backup and the documented audit tool; do not silently remove existing financial rows.',
      'Freeze finalized invoice/PDF values so later profile, time or rate edits cannot unexpectedly modify a sent or paid invoice.',
    ],
    rules: [
      'Pending/rejected overtime has zero billable overtime charge; full or partial approvals use only approved minutes/custom amounts.',
      'Historical disabled schedules can remain valid when an attendance record links to a genuinely assigned historical shift.',
      'An in-app successful invoice generation action does not prove PDF totals agree; verify hours and charges before issuing.',
    ],
  },
  {
    key: 'payroll-probation-status', group: 'People', title: 'Probation and salary workflows', module: 'employees', path: base => `${base}/employees`,
    summary: 'Existing profile/probation fields and reminders versus workflows that still require full end-to-end validation.',
    who: 'Administrators and authorized HR.',
    steps: [
      'Record employee probation dates and monitor due reminders where configured.',
      'The intended probation lifecycle includes start, extend, complete, terminate and decision history; verify these controls before using them as an authoritative HR record.',
      'Keep employee compensation distinct from Doctor billing rates. Hourly and non-VA salaried staff may require different payroll calculations.',
      'The full salary-slip generation, HR approval and distribution lifecycle is not yet verified as complete in this codebase.',
    ],
    rules: [
      'Do not claim that a feature is payroll-approved based on an employee salary field or an invoice salary row alone.',
      'Preserve historical employee and HR decisions rather than removing records on account offboarding.',
    ],
  },
];
