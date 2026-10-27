import { useEffect, useMemo, useState } from 'react';
import { CakeSlice, PartyPopper } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import api from '../api';

function dashboardPathFor(role) {
  if (role === 'employee') return '/employee/dashboard';
  if (role === 'hr') return '/hr/dashboard';
  if (role === 'admin' || role === 'superadmin') return '/admin/dashboard';
  return null;
}

function initials(name) {
  return String(name || 'VA').split(' ').filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase();
}

function BirthdayAvatar({ person }) {
  const [broken, setBroken] = useState(false);
  if (person?.profile_picture && !broken) {
    return <img className="birthday-banner-avatar" src={person.profile_picture} alt={person.name || 'Birthday'} onError={() => setBroken(true)} />;
  }
  return <span className="birthday-banner-avatar fallback">{initials(person?.name)}</span>;
}

export default function BirthdayBanner({ user }) {
  const location = useLocation();
  const [payload, setPayload] = useState(user?.birthday || null);
  const dashboardPath = dashboardPathFor(user?.role);
  const isDashboard = Boolean(dashboardPath && location.pathname === dashboardPath);

  useEffect(() => {
    setPayload(user?.birthday || null);
  }, [user?.birthday?.date_key, user?.id]);

  useEffect(() => {
    if (!isDashboard || !user?.id) return undefined;
    let alive = true;

    const refresh = async () => {
      try {
        const { data } = await api.get('/me');
        if (alive) setPayload(data?.user?.birthday || null);
      } catch {}
    };

    refresh();
    const interval = window.setInterval(refresh, 5 * 60 * 1000);
    const onFocus = () => refresh();
    window.addEventListener('focus', onFocus);

    return () => {
      alive = false;
      window.clearInterval(interval);
      window.removeEventListener('focus', onFocus);
    };
  }, [isDashboard, user?.id]);

  const people = useMemo(() => Array.isArray(payload?.employees) ? payload.employees : [], [payload?.employees]);
  if (!isDashboard || !payload?.today || !people.length) return null;

  const employeeView = user?.role === 'employee';
  const first = people[0];

  return <section className={`birthday-dashboard-banner ${employeeView ? 'is-employee' : 'is-operations'}`} aria-label="Birthday celebration">
    <div className="birthday-banner-icon"><CakeSlice size={24}/></div>
    <div className="birthday-banner-copy">
      <span><PartyPopper size={14}/> CareVMA birthday</span>
      {employeeView ? <>
        <strong>Happy Birthday, {first?.name?.split(' ')?.[0] || 'Team Member'}! 🎉</strong>
        <p>Wishing you a wonderful birthday and a great year ahead. This banner stays on your dashboard for your full birthday in {first?.timezone || 'your assigned timezone'}.</p>
      </> : <>
        <strong>{people.length === 1 ? `${first.name} is celebrating a birthday today 🎂` : `${people.length} team members are celebrating birthdays today 🎂`}</strong>
        <p>Birthdays are matched from each VA profile Date of Birth using that VA's assigned timezone.</p>
      </>}
    </div>
    <div className="birthday-banner-people">
      {people.slice(0, 6).map(person => <div className="birthday-banner-person" key={person.id} title={`${person.name} · ${person.timezone}`}>
        <BirthdayAvatar person={person}/>
        <div><strong>{person.name}</strong><span>{person.timezone}</span></div>
      </div>)}
      {people.length > 6 && <span className="birthday-banner-more">+{people.length - 6}</span>}
    </div>
  </section>;
}
