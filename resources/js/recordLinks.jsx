import { Link } from 'react-router-dom';
import { useAuth } from './context/AuthContext';
import { can } from './permissions';

// Record names become links to the record's page for the roles that have one:
// admin and HR open employee profiles, staff granted the module open client profiles and invoices,
// a doctor opens the VA's attendance page. Everyone else sees plain text.
const ADMIN = ['admin', 'superadmin'];
export const employeePath = (role, id) => !id ? null : ADMIN.includes(role) ? `/admin/employees/${id}` : role === 'hr' ? `/hr/employees/${id}` : role === 'client' ? `/doctor/employee/${id}/attendance` : null;
export const clientPath = (user, id) => id && can(user, 'clients') ? `/admin/client/${id}/profile` : null;
export const invoicePath = (user, id) => id && can(user, 'invoices') ? `/admin/invoices/${id}/edit` : null;
export const fullName = e => `${e?.first_name || ''} ${e?.last_name || ''}`.trim();

export function RecordLink({ to, className = '', children }) {
  return to ? <Link className={`record-link ${className}`.trim()} to={to} onClick={e => e.stopPropagation()}>{children}</Link> : <>{children}</>;
}
export function EmployeeLink({ employee, children, ...rest }) {
  const { user } = useAuth();
  return <RecordLink to={employeePath(user?.role, employee?.id)} {...rest}>{children ?? (fullName(employee) || '—')}</RecordLink>;
}
export function ClientLink({ client, children, ...rest }) {
  const { user } = useAuth();
  return <RecordLink to={clientPath(user, client?.id)} {...rest}>{children ?? (client?.name || client?.business_name || '—')}</RecordLink>;
}
export function InvoiceLink({ invoice, children, ...rest }) {
  const { user } = useAuth();
  return <RecordLink to={invoicePath(user, invoice?.id)} {...rest}>{children ?? (invoice?.invoice_number || `#${invoice?.id}`)}</RecordLink>;
}
