import React from 'react';
import { can } from './permissions';
import DocsPage from './pages/Docs';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Link, Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import AppShell from './components/AppShell';
import { Loading } from './components/ui';
import { LoginPage, ForgotPasswordPage, ResetPasswordPage } from './pages/Login';
import {
  ManagementDashboard, LiveUsersPage, EmployeesPage, EmployeeProfilePage, ClientsPage, SchedulesPage, AttendancePage,
  ChangeRequestsPage, OvertimePage, InvoicesPage, CompanyFinancePage, ReferencePage, NotificationsPage, AccountPage, HrTeamPage, ActivityLogsPage, InvoiceSettingsPage, RolesPermissionsPage
} from './pages/Management';
import { EmployeeDashboard, EmployeeSchedule, EmployeeAttendance, EmployeeProfile, EmployeeSettings } from './pages/Employee';
import { DoctorDashboard, DoctorEmployeeAttendance, DoctorProfile } from './pages/Doctor';
import EmployeeWizardPage from './pages/EmployeeWizard';
import ClientProfilePage from './pages/ClientProfile';
import NotebookPage from './pages/Notebook';
import SharedDocumentsPage from './pages/SharedDocuments';
import '../css/app.css';
import '../css/carevma-professional-theme.css';
import '../css/design-system.css';
import GlobalFeedbackHost from './components/GlobalFeedbackHost';

function dashboardFor(user) {
  if (!user) return '/login';
  if (['admin','superadmin'].includes(user.role)) return '/admin/dashboard';
  if (user.role === 'hr') return '/hr/dashboard';
  if (user.role === 'employee') {
    const complete = user?.employee?.profile_completed;
    if (complete === false || complete === 0 || complete === '0') return '/employee/profile';
    return '/employee/dashboard';
  }
  if (user.role === 'client') return '/doctor/dashboard';
  return '/login';
}

function Protected({ roles, module, children }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <div className="content"><Loading/></div>;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }}/>;
  if (roles && !roles.includes(user.role) && !(roles.includes('admin') && user.role === 'superadmin')) return <Navigate to={dashboardFor(user)} replace/>;
  // A module route also needs the grant from Roles & Permissions (admins always have it).
  if (module && !can(user, module)) return <Navigate to={dashboardFor(user)} replace/>;
  const incompleteEmployee = user.role === 'employee' && (user?.employee?.profile_completed === false || user?.employee?.profile_completed === 0 || user?.employee?.profile_completed === '0');
  if (incompleteEmployee && location.pathname !== '/employee/profile') return <Navigate to="/employee/profile" replace/>;
  return children;
}

function GuestOnly({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="content"><Loading/></div>;
  return user ? <Navigate to={dashboardFor(user)} replace/> : children;
}

function HomeRedirect() {
  const { user, loading } = useAuth();
  if (loading) return <div className="content"><Loading/></div>;
  return <Navigate to={dashboardFor(user)} replace/>;
}

function LegacyEmployeeRedirect() {
  const { employeeId } = useParams();
  return <Navigate to={`/admin/employees/${employeeId}`} replace/>;
}

function NotFound() {
  const { user } = useAuth();
  return <div className="not-found"><div className="not-found-code">404</div><h1>That page doesn’t exist.</h1><p>The link may be outdated. CareVMA kept your session safe.</p><Link className="btn btn-primary btn-link" to={dashboardFor(user)}>Return to dashboard</Link></div>;
}

function AppRoutes() {
  return <Routes>
    <Route path="/" element={<HomeRedirect/>}/>
    <Route path="/login" element={<GuestOnly><LoginPage/></GuestOnly>}/>
    <Route path="/forgot-password" element={<GuestOnly><ForgotPasswordPage/></GuestOnly>}/>
    <Route path="/reset-password/:token" element={<GuestOnly><ResetPasswordPage/></GuestOnly>}/>

    <Route element={<Protected roles={['admin','hr']}><AppShell/></Protected>}>
      <Route path="/admin/dashboard" element={<Protected roles={['admin']}><ManagementDashboard/></Protected>}/>
      <Route path="/admin/live-users" element={<Protected roles={['admin']} module="live-users"><LiveUsersPage/></Protected>}/>
      <Route path="/admin/activity-logs" element={<Protected roles={['admin','hr']} module="activity-logs"><ActivityLogsPage/></Protected>}/>
      <Route path="/admin/invoice-settings" element={<Protected roles={['admin','hr']} module="invoice-settings"><InvoiceSettingsPage/></Protected>}/>
      <Route path="/admin/roles-permissions" element={<Protected roles={['admin']}><RolesPermissionsPage/></Protected>}/>
      <Route path="/admin/docs" element={<Protected roles={['admin']}><DocsPage/></Protected>}/>
      <Route path="/hr/docs" element={<Protected roles={['hr']}><DocsPage/></Protected>}/>
      <Route path="/hr/dashboard" element={<Protected roles={['hr']}><ManagementDashboard/></Protected>}/>
      <Route path="/hr/live-users" element={<Protected roles={['hr']} module="live-users"><LiveUsersPage/></Protected>}/>
      <Route path="/admin/employees" element={<Protected roles={['admin']} module="employees"><EmployeesPage/></Protected>}/>
      <Route path="/admin/employees/new" element={<Protected roles={['admin']} module="employees"><EmployeeWizardPage/></Protected>}/>
      <Route path="/admin/employees/:employeeId" element={<Protected roles={['admin']} module="employees"><EmployeeProfilePage/></Protected>}/>
      <Route path="/admin/hr-team" element={<Protected roles={['admin','hr']} module="hr-team"><HrTeamPage/></Protected>}/>
      <Route path="/hr/employees" element={<Protected roles={['hr']} module="employees"><EmployeesPage/></Protected>}/>
      <Route path="/hr/employees/new" element={<Protected roles={['hr']} module="employees"><EmployeeWizardPage/></Protected>}/>
      <Route path="/hr/employees/:employeeId" element={<Protected roles={['hr']} module="employees"><EmployeeProfilePage/></Protected>}/>
      <Route path="/admin/schedules" element={<Protected roles={['admin']} module="schedules"><SchedulesPage/></Protected>}/>
      <Route path="/hr/schedules" element={<Protected roles={['hr']} module="schedules"><SchedulesPage/></Protected>}/>
      <Route path="/admin/attendance" element={<Protected roles={['admin']} module="attendance"><AttendancePage/></Protected>}/>
      <Route path="/hr/attendance" element={<Protected roles={['hr']} module="attendance"><AttendancePage/></Protected>}/>
      <Route path="/admin/change-requests" element={<Protected roles={['admin']} module="change-requests"><ChangeRequestsPage/></Protected>}/>
      <Route path="/hr/change-requests" element={<Protected roles={['hr']} module="change-requests"><ChangeRequestsPage/></Protected>}/>
      <Route path="/admin/overtime" element={<Protected roles={['admin']} module="overtime"><OvertimePage/></Protected>}/>
      <Route path="/hr/overtime" element={<Protected roles={['hr']} module="overtime"><OvertimePage/></Protected>}/>
      <Route path="/admin/options/:type" element={<Protected roles={['admin']} module="configuration"><ReferencePage/></Protected>}/>
      <Route path="/hr/options/:type" element={<Protected roles={['hr']} module="configuration"><ReferencePage/></Protected>}/>
      <Route path="/admin/notifications" element={<Protected roles={['admin']}><NotificationsPage/></Protected>}/>
      <Route path="/hr/notifications" element={<Protected roles={['hr']}><NotificationsPage/></Protected>}/>
      <Route path="/admin/account" element={<Protected roles={['admin']}><AccountPage/></Protected>}/>
      <Route path="/hr/account" element={<Protected roles={['hr']}><AccountPage/></Protected>}/>
      <Route path="/admin/clients" element={<Protected roles={['admin','hr']} module="clients"><ClientsPage/></Protected>}/>
      <Route path="/admin/client/:clientId/profile" element={<Protected roles={['admin','hr']} module="clients"><ClientProfilePage/></Protected>}/>
      <Route path="/admin/client/:clientId/profile-timezone" element={<Protected roles={['admin','hr']} module="clients"><ClientProfilePage/></Protected>}/>
      <Route path="/admin/client/:clientId/schedule" element={<Protected roles={['admin','hr']} module="clients"><ClientProfilePage/></Protected>}/>
      <Route path="/admin/client/:clientId/assign-employee" element={<Protected roles={['admin','hr']} module="clients"><ClientProfilePage/></Protected>}/>
      <Route path="/admin/client/:clientId/daily-work-report" element={<Protected roles={['admin','hr']} module="clients"><ClientProfilePage/></Protected>}/>
      <Route path="/admin/invoices" element={<Protected roles={['admin','hr']} module="invoices"><InvoicesPage/></Protected>}/>
      <Route path="/admin/company-finance" element={<Protected roles={['admin','hr']} module="company-finance"><CompanyFinancePage/></Protected>}/>
      <Route path="/admin/notebook" element={<Protected roles={['admin']} module="notebook"><NotebookPage/></Protected>}/>
      <Route path="/hr/notebook" element={<Protected roles={['hr']} module="notebook"><NotebookPage/></Protected>}/>
    </Route>

    <Route element={<Protected roles={['employee']}><AppShell/></Protected>}>
      <Route path="/employee/dashboard" element={<EmployeeDashboard/>}/>
      <Route path="/employee/schedule" element={<EmployeeSchedule/>}/>
      <Route path="/employee/my-schedule" element={<Navigate to="/employee/schedule" replace/>}/>
      <Route path="/employee/attendance" element={<EmployeeAttendance/>}/>
      <Route path="/employee/my-attendance" element={<Navigate to="/employee/attendance" replace/>}/>
      <Route path="/employee/profile" element={<EmployeeProfile/>}/>
      <Route path="/employee/notifications" element={<NotificationsPage/>}/>
      <Route path="/employee/dashboard/profile/edit" element={<Navigate to="/employee/profile" replace/>}/>
      <Route path="/employee/settings" element={<EmployeeSettings/>}/>
      <Route path="/employee/documents" element={<SharedDocumentsPage/>}/>
      <Route path="/employee/notebook" element={<Navigate to="/employee/documents" replace/>}/>
    </Route>

    <Route element={<Protected roles={['client']}><AppShell/></Protected>}>
      <Route path="/doctor/dashboard" element={<DoctorDashboard/>}/>
      <Route path="/doctor/employee/:employeeId/attendance" element={<DoctorEmployeeAttendance/>}/>
      <Route path="/doctor/notifications" element={<NotificationsPage/>}/>
      <Route path="/doctor/profile" element={<DoctorProfile/>}/>
      <Route path="/doctor/documents" element={<SharedDocumentsPage/>}/>
      <Route path="/doctor/notebook" element={<Navigate to="/doctor/documents" replace/>}/>
    </Route>

    {/* Doctor invoice access is intentionally disabled. Old links return to the doctor dashboard. */}
    <Route path="/doctor/invoices" element={<Navigate to="/doctor/dashboard" replace/>}/>

    {/* Old admin URLs resolve inside the SPA instead of becoming broken links. */}
    <Route path="/admin/add-employee" element={<Navigate to="/admin/employees/new" replace/>}/>
    <Route path="/admin/add-client" element={<Navigate to="/admin/clients" replace/>}/>
    <Route path="/admin/add-invoice" element={<Navigate to="/admin/invoices" replace/>}/>
    <Route path="/admin/invoices/:invoiceId/edit" element={<Navigate to="/admin/invoices" replace/>}/>
    <Route path="/admin/pages-profile-user.html" element={<Navigate to="/admin/account" replace/>}/>
    <Route path="/admin/pages-account-settings-account.html" element={<Navigate to="/admin/account" replace/>}/>
    <Route path="/pages-profile-user.html" element={<Navigate to="/admin/account" replace/>}/>
    <Route path="/pages-account-settings-account.html" element={<Navigate to="/admin/account" replace/>}/>
    <Route path="/admin/employee-options" element={<Navigate to="/admin/options/departments" replace/>}/>
    <Route path="/admin/edit-employee/profile/:employeeId" element={<LegacyEmployeeRedirect/>}/>
    <Route path="/admin/attendance-requests" element={<Navigate to="/admin/change-requests" replace/>}/>
    <Route path="/admin/reporting-managers" element={<Navigate to="/admin/options/reporting-managers" replace/>}/>
    <Route path="/admin/departments" element={<Navigate to="/admin/options/departments" replace/>}/>
    <Route path="/admin/roles" element={<Navigate to="/admin/options/roles" replace/>}/>
    <Route path="/admin/employment-types" element={<Navigate to="/admin/options/employment-types" replace/>}/>
    <Route path="/admin/shift-types" element={<Navigate to="/admin/options/shift-types" replace/>}/>
    <Route path="/admin/designations" element={<Navigate to="/admin/options/designations" replace/>}/>
    <Route path="/admin/employee-statuses" element={<Navigate to="/admin/options/employee-statuses" replace/>}/>
    <Route path="/admin/expertises" element={<Navigate to="/admin/options/expertises" replace/>}/>
    <Route path="/admin/services" element={<Navigate to="/admin/options/services" replace/>}/>
    <Route path="/admin/contracts" element={<Navigate to="/admin/options/contracts" replace/>}/>

    <Route path="*" element={<NotFound/>}/>
  </Routes>;
}

createRoot(document.getElementById('app')).render(
  <React.StrictMode>
    <BrowserRouter>
      <GlobalFeedbackHost/>
      <AuthProvider><AppRoutes/></AuthProvider>
    </BrowserRouter>
  </React.StrictMode>
);
