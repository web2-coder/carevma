import { Bell, Cake, CalendarDays, ClipboardList, Clock3, FileText, KeyRound, ReceiptText, Stethoscope, TimerReset, UserRound } from 'lucide-react';

// Most notifications share one generic type, so the module is read from the
// action URL and title. First matching rule wins; the bell is the fallback.
const RULES = [
  [/birthday/i, Cake],
  [/invoice|billing|payment/i, ReceiptText],
  [/overtime/i, TimerReset],
  [/change[- ]?request|correction|override/i, ClipboardList],
  [/attendance|clock|check[- ]?in|check[- ]?out|\blate\b|break/i, Clock3],
  [/schedule|shift|assign/i, CalendarDays],
  [/password|activation|sign[- ]?in|login|security/i, KeyRound],
  [/\bnotebook\b|\bdocuments?\b|\bfiles?\b/i, FileText],
  [/employee|profile|team member|\bva\b/i, UserRound],
  [/client|doctor/i, Stethoscope],
];

export function notificationIcon(row) {
  const haystack = `${row?.type || ''} ${row?.action_url || ''} ${row?.title || ''}`;
  return (RULES.find(([pattern]) => pattern.test(haystack)) || [null, Bell])[1];
}

export function NotificationIcon({ row, size = 16 }) {
  const Icon = notificationIcon(row);
  return <Icon size={size}/>;
}
