import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowUpRight } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { PageHeader, Card, SearchBox, Badge, EmptyState } from '../components/ui';

// Public-facing help for the two self-service portals. Keep this content confined
// to actions the respective role can actually access through its own navigation.
const EMPLOYEE_HELP = [
  {
    id: 'start', title: 'First login and getting started', path: '/employee/profile',
    summary: 'Complete your personal profile before using the rest of the VA portal.',
    steps: [
      'Sign in using the account activation or login details provided by CareVMA.',
      'If prompted to complete your profile, open My Profile and fill in the required fields.',
      'Save or complete your profile. Dashboard and other pages become available when setup is complete.',
      'Review My Schedule before the start of your workday.'
    ],
    rules: ['Your account shows only your own permitted information.', 'If your profile is incomplete, other self-service pages may remain locked.']
  },
  {
    id: 'dashboard', title: 'Dashboard and workday timer', path: '/employee/dashboard',
    summary: 'See your current work session, shift, timers, and check-in actions.',
    steps: [
      'Check the Doctor and shift information shown on your dashboard.',
      'Use Check in when the permitted check-in window opens.',
      'Use the available break controls to start and end your break.',
      'Use Check out to finish your work session when your work is complete.',
      'When extra time is worked after a Doctor shift, the overtime timer may show a separate orange/amber indicator.'
    ],
    rules: [
      'A single VA check-in can cover more than one assigned Doctor; their shift segments are tracked separately.',
      'Check-in may open up to 15 minutes before the Doctor shift. Early arrival does not add extra regular billable time.',
      'A live overtime timer is an estimate, not an approval or a promise of paid overtime.',
      'An overdue work session can be automatically closed after the applicable two-hour post-shift window.'
    ]
  },
  {
    id: 'schedule', title: 'My Schedule', path: '/employee/schedule',
    summary: 'Find the Doctors and shifts assigned to you.',
    steps: [
      'Open My Schedule to view Doctor assignments, work dates, shift times, timezones, and break allowances.',
      'Use the available search or filters to locate a particular Doctor or date.',
      'Use Change Request where offered if the assigned schedule needs correction; provide a clear reason.'
    ],
    rules: ['Doctor-local shift times determine attendance calculations; your own timezone may be shown for convenience.', 'Only assigned work dates and shifts should be treated as scheduled work.']
  },
  {
    id: 'attendance', title: 'My Attendance', path: '/employee/attendance',
    summary: 'Review your recorded check-ins, check-outs, breaks, and overtime.',
    steps: [
      'Open My Attendance to review your own work records.',
      'Use the date range and search tools to find a particular attendance entry.',
      'Check the Doctor timezone, worked time, break, late arrival, and overtime fields.',
      'If an entry is incorrect, use the available correction/change-request action and explain the problem.'
    ],
    rules: ['Pending overtime is not the same as approved overtime.', 'Attendance changes may require an Admin/HR decision.']
  },
  {
    id: 'profile', title: 'My Profile', path: '/employee/profile',
    summary: 'Keep your personal profile and profile photo up to date.',
    steps: ['Open My Profile and review the fields you are permitted to edit.', 'Update details or choose a profile photo if needed.', 'Select Save changes and check the confirmation message.'],
    rules: ['Administrative employment information and email identity may be restricted from editing.', 'Documents shared by Admin/HR are available on the separate Shared Documents page.']
  },
  {
    id: 'documents', title: 'Shared Documents', path: '/employee/documents',
    summary: 'Read files specifically shared with your account.',
    steps: ['Open Shared Documents.', 'Search or browse the list.', 'Open a file using the document link.'],
    rules: ['VA access to shared documents is view-only.', 'Files not shared with you should not appear in your list.']
  },
  {
    id: 'notifications', title: 'Notifications', path: '/employee/notifications',
    summary: 'Read alerts relevant to your own account and work.',
    steps: ['Open Notifications.', 'Review new messages and follow any relevant attendance or profile action.', 'Use available controls to mark notifications as read.'],
    rules: ['The availability of email notifications depends on system mail delivery.']
  },
  {
    id: 'settings', title: 'Settings and account security', path: '/employee/settings',
    summary: 'Change your password or connect an approved Google account.',
    steps: ['Open Settings.', 'To change your password, enter the current password and confirm the new password.', 'Use Connect Google if your organization permits it.'],
    rules: ['Do not share your password or login session.', 'Contact your administrator if account access or account activation fails.']
  }
];

const DOCTOR_HELP = [
  {
    id: 'start', title: 'Getting started', path: '/doctor/dashboard',
    summary: 'Understand what you can see as a Doctor/Client.',
    steps: ['Sign in with your Doctor/Client account.', 'Open Assigned Team to see the VAs connected to your account.', 'Check each assigned VA schedule and visit their attendance records as needed.'],
    rules: ['You can see only employees and records authorized for your Client account.', 'The Doctor/Client portal does not currently offer an invoice page.']
  },
  {
    id: 'team', title: 'Assigned Team and VA schedules', path: '/doctor/dashboard',
    summary: 'See assigned employees, status, shift times and allowed breaks.',
    steps: ['Open Assigned Team.', 'Review the VA schedule table for assigned days, times and breaks.', 'Select an assigned VA card to open that employee\'s attendance.', 'Review displayed online or attendance status when available.'],
    rules: ['Schedule times are presented in your Doctor timezone.', 'Only VAs assigned to your account should be listed.']
  },
  {
    id: 'attendance', title: 'Review a VA attendance record', path: '/doctor/dashboard',
    summary: 'Use an assigned VA card to open attendance history.',
    steps: ['Open Assigned Team and select a VA.', 'Review attendance dates, check-in, check-out, breaks, worked time, lateness, and overtime.', 'Use available date filters, search, sorting and pagination to inspect history.'],
    rules: ['Doctor-facing attendance timestamps are displayed in your Doctor timezone.', 'Displayed overtime is not necessarily approved for billing.', 'Attendance approvals and employee management remain with authorized CareVMA staff.']
  },
  {
    id: 'documents', title: 'Shared Documents', path: '/doctor/documents',
    summary: 'Access documents shared with your Doctor/Client account.',
    steps: ['Open Shared Documents.', 'Search for a document or browse the list.', 'Open the shared file link to view the attachment.'],
    rules: ['You see only documents specifically shared with your account.', 'The page is intended for viewing, not uploading or editing staff documents.']
  },
  {
    id: 'notifications', title: 'Notifications', path: '/doctor/notifications',
    summary: 'Read account and service alerts.',
    steps: ['Open Notifications.', 'Review recent notifications and follow relevant links if provided.', 'Use available options to mark items as read.'],
    rules: ['Notifications are limited to information relevant to your account.']
  },
  {
    id: 'profile', title: 'My Profile and password', path: '/doctor/profile',
    summary: 'Maintain your profile details, timezone, photo and account password.',
    steps: ['Open My Profile.', 'Review the name, contact, business and timezone fields.', 'Choose a profile photo if needed, then select Update profile.', 'To change your password, fill in the current password, new password and confirmation.'],
    rules: ['Your timezone controls how Doctor-local dates and times are presented.', 'Your profile changes do not grant access to other Doctors or their employees.']
  }
];

export default function RoleHelp() {
  const { user } = useAuth();
  const isEmployee = user?.role === 'employee';
  const sections = isEmployee ? EMPLOYEE_HELP : DOCTOR_HELP;
  const [query, setQuery] = useState('');
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return sections.filter(section => !needle || [section.title, section.summary, ...section.steps, ...section.rules].join(' ').toLowerCase().includes(needle));
  }, [sections, query]);

  return <>
    <PageHeader eyebrow="Help" title={isEmployee ? 'VA Help Guide' : 'Doctor / Client Help Guide'}
      description={isEmployee ? 'Instructions for the pages available in your own VA portal.' : 'Instructions for the pages available in your own Doctor / Client portal.'}
      actions={<SearchBox value={query} onChange={setQuery} placeholder="Search help topics…"/>}/>
    <div className="docs-layout">
      <nav className="docs-toc" aria-label="Help topics">
        <div className="docs-toc-group">Your portal</div>
        {filtered.map(section => <a href={`#help-${section.id}`} key={section.id}>{section.title}</a>)}
      </nav>
      <div>
        {!filtered.length && <Card><EmptyState title="No matching help topic" description="Try a different search phrase."/></Card>}
        {filtered.map(section => <section id={`help-${section.id}`} className="docs-section" key={section.id}>
          <Card title={section.title} subtitle={section.summary}
            actions={<Link className="btn btn-secondary btn-link" to={section.path}>Open page <ArrowUpRight size={15}/></Link>}>
            <div className="docs-meta"><Badge tone="info">{isEmployee ? 'VA portal' : 'Doctor portal'}</Badge></div>
            <h4>How to use</h4>
            <ol>{section.steps.map((step, index) => <li key={index}>{step}</li>)}</ol>
            <h4>Important rules</h4>
            <ul>{section.rules.map((rule, index) => <li key={index}>{rule}</li>)}</ul>
          </Card>
        </section>)}
      </div>
    </div>
  </>;
}
