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

  useEffect(() => {
    setPayload(user?.birthday || null);
  }, [user?.birthday?.date_key, user?.id]);

  useEffect(() => {
    if (!isDashboard || !user?.id) return undefined;

    let alive = true;
    const refreshBirthday = async () => {
      try {
        const { data } = await api.get('/me');
        if (alive) setPayload(data?.user?.birthday || null);
      } catch {}
    };

    refreshBirthday();
    return () => { alive = false; };
  }, [isDashboard, user?.id]);

  const people = useMemo(() => Array.isArray(payload?.employees) ? payload.employees : [], [payload?.employees]);

  useEffect(() => {
    if (!isDashboard || !payload?.today || !people.length || !user?.id) {
      setOpen(false);
      return undefined;
    }

    const dateKey = payload?.date_key || 'today';
    const storageKey = `carevma:birthday-popup:${user.id}:${dateKey}`;
    if (sessionStorage.getItem(storageKey) === 'shown') return undefined;

    const timer = setTimeout(() => {
      setOpen(true);
      sessionStorage.setItem(storageKey, 'shown');
    }, 650);

    return () => clearTimeout(timer);
  }, [isDashboard, payload?.today, payload?.date_key, people.length, user?.id]);

  if (!open || !people.length) return null;

  const isEmployee = user?.role === 'employee';
  const first = people[0];

  return <div className="birthday-overlay" role="dialog" aria-modal="true" aria-label="Birthday celebration">
    <div className="birthday-confetti" aria-hidden="true">
      {Array.from({ length: 18 }).map((_, index) => <i key={index} style={{ '--i': index }}/>) }
    </div>
    <div className="birthday-card">
      <button type="button" className="birthday-close" onClick={() => setOpen(false)} aria-label="Close birthday popup"><X size={18}/></button>
      <div className="birthday-icon"><CakeSlice size={34}/></div>
      <span className="birthday-eyebrow"><PartyPopper size={15}/> CareVMA celebration</span>
      {isEmployee ? <>
        <h2>Happy Birthday, {first.name?.split(' ')[0] || 'Team Member'}! 🎉</h2>
        <p>Wishing you a wonderful birthday and a great year ahead from the CareVMA team.</p>
        <div className="birthday-person single">
          <div className="birthday-avatar">{String(first.name || 'VA').split(' ').map(part => part[0]).slice(0,2).join('').toUpperCase()}</div>
          <div><strong>{first.name}</strong><span>{first.timezone}</span></div>
        </div>
      </> : <>
        <h2>Today’s birthdays 🎂</h2>
        <p>{people.length === 1 ? 'One team member is celebrating today.' : `${people.length} team members are celebrating today.`} Send them your best wishes.</p>
        <div className="birthday-people">
          {people.map(person => <div className="birthday-person" key={person.id}>
            <div className="birthday-avatar">{String(person.name || 'VA').split(' ').map(part => part[0]).slice(0,2).join('').toUpperCase()}</div>
            <div><strong>{person.name}</strong><span>{person.timezone}</span></div>
          </div>)}
        </div>
      </>}
      <button type="button" className="birthday-action" onClick={() => setOpen(false)}>Celebrate</button>
    </div>
  </div>;
}
