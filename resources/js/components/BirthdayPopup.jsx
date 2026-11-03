import { useEffect, useMemo, useState } from 'react';
import { CakeSlice, PartyPopper, X } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import api from '../api';

function dashboardPathFor(role) {
  if (role === 'employee') return '/employee/dashboard';
  if (role === 'hr') return '/hr/dashboard';
  if (role === 'admin' || role === 'superadmin') return '/admin/dashboard';
  return null;
}

export default function BirthdayPopup({ user }) {
  const location = useLocation();
  const [payload, setPayload] = useState(user?.birthday || null);
  const [open, setOpen] = useState(false);
  const dashboardPath = dashboardPathFor(user?.role);
  const isDashboard = Boolean(dashboardPath && location.pathname === dashboardPath);

  useEffect(() => setPayload(user?.birthday || null), [user?.birthday?.date_key, user?.id]);

  useEffect(() => {
    if (!isDashboard || !user?.id) return undefined;
    let alive = true;
    api.get('/me').then(({ data }) => { if (alive) setPayload(data?.user?.birthday || null); }).catch(() => {});
    return () => { alive = false; };
  }, [isDashboard, user?.id]);

  const people = useMemo(() => Array.isArray(payload?.employees) ? payload.employees : [], [payload?.employees]);
  const self = people.find(person => person.is_self || String(person.id) === String(payload?.self_employee_id));
  const popupPeople = user?.role === 'employee' && self ? [self] : [];

  useEffect(() => {
    const eligible = isDashboard && user?.role === 'employee' && payload?.today && payload?.is_self_birthday && !payload?.popup_seen && popupPeople.length && user?.id;
    if (!eligible) { setOpen(false); return undefined; }

    const storageKey = `carevma:birthday-popup:${user.id}:${payload?.date_key || 'today'}`;
    // localStorage survives logout/login, so the birthday popup is shown once per user/day.
    if (window.localStorage.getItem(storageKey) === 'shown') return undefined;
    const timer = window.setTimeout(() => {
      setOpen(true);
      window.localStorage.setItem(storageKey, 'shown');
      api.post('/birthday-popup/seen').catch(() => {});
    }, 500);
    return () => window.clearTimeout(timer);
  }, [isDashboard, payload?.today, payload?.is_self_birthday, payload?.popup_seen, payload?.date_key, popupPeople.length, user?.id, user?.role]);

  if (!open || !popupPeople.length) return null;
  const first = popupPeople[0];

  return <div className="birthday-overlay" role="dialog" aria-modal="true" aria-label="Birthday celebration">
    <div className="birthday-confetti" aria-hidden="true">{Array.from({ length: 18 }).map((_, index) => <i key={index} style={{ '--i': index }}/>)}</div>
    <div className="birthday-card">
      <button type="button" className="birthday-close" onClick={() => setOpen(false)} aria-label="Close birthday popup"><X size={18}/></button>
      <div className="birthday-icon"><CakeSlice size={34}/></div>
      <span className="birthday-eyebrow"><PartyPopper size={15}/> CareVMA celebration</span>
      <h2>Happy Birthday, {first.name?.split(' ')[0] || 'Team Member'}! 🎉</h2>
      <p>Wishing you a wonderful birthday and a great year ahead from the CareVMA team.</p>
      <div className="birthday-people">
        {popupPeople.map(person => <div className="birthday-person" key={person.id}>
          <div className="birthday-avatar">{String(person.name || 'VA').split(' ').map(part => part[0]).slice(0,2).join('').toUpperCase()}</div>
          <div><strong>{person.name}</strong><span>{person.timezone}</span></div>
        </div>)}
      </div>
      <button type="button" className="birthday-action" onClick={() => setOpen(false)}>Celebrate</button>
    </div>
  </div>;
}
