import { useEffect, useMemo, useState } from 'react';
import { CakeSlice, PartyPopper, Send } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import api from '../api';

function dashboardPathFor(role) {
  if (role === 'employee') return '/employee/dashboard';
  if (role === 'hr') return '/hr/dashboard';
  if (role === 'admin' || role === 'superadmin') return '/admin/dashboard';
  return null;
}
function initials(name) { return String(name || 'VA').split(' ').filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase(); }
function BirthdayAvatar({ person }) {
  const [broken, setBroken] = useState(false);
  if (person?.profile_picture && !broken) return <img className="birthday-banner-avatar" src={person.profile_picture} alt={person.name || 'Birthday'} onError={() => setBroken(true)} />;
  return <span className="birthday-banner-avatar fallback">{initials(person?.name)}</span>;
}

export default function BirthdayBanner({ user }) {
  const location = useLocation();
  const [payload, setPayload] = useState(user?.birthday || null);
  const [sending, setSending] = useState(null);
  const dashboardPath = dashboardPathFor(user?.role);
  const isDashboard = Boolean(dashboardPath && location.pathname === dashboardPath);

  const refresh = async () => {
    try { const { data } = await api.get('/me'); setPayload(data?.user?.birthday || null); } catch {}
  };

  useEffect(() => setPayload(user?.birthday || null), [user?.birthday?.date_key, user?.id]);
  useEffect(() => {
    if (!isDashboard || !user?.id) return undefined;
    refresh();
    const interval = window.setInterval(refresh, 5 * 60 * 1000);
    window.addEventListener('focus', refresh);
    return () => { window.clearInterval(interval); window.removeEventListener('focus', refresh); };
  }, [isDashboard, user?.id]);

  const people = useMemo(() => Array.isArray(payload?.employees) ? payload.employees : [], [payload?.employees]);
  const received = useMemo(() => Array.isArray(payload?.stickers_received) ? payload.stickers_received : [], [payload?.stickers_received]);
  if (!isDashboard || !payload?.today || !people.length) return null;

  const employeeView = user?.role === 'employee';
  const self = people.find(person => person.is_self || String(person.id) === String(payload?.self_employee_id));
  const first = self || people[0];
  const sendSticker = async person => {
    setSending(person.id);
    try { await api.post(`/employee/birthday-wishes/${person.id}`); await refresh(); } catch {} finally { setSending(null); }
  };

  return <section className={`birthday-dashboard-banner ${employeeView ? 'is-employee' : 'is-operations'}`} aria-label="Birthday celebration">
    <div className="birthday-banner-icon"><CakeSlice size={24}/></div>
    <div className="birthday-banner-copy">
      <span><PartyPopper size={14}/> CareVMA birthday</span>
      {employeeView && payload?.is_self_birthday ? <>
        <strong>Happy Birthday, {first?.name?.split(' ')?.[0] || 'Team Member'}! 🎉</strong>
        <p>Your birthday banner stays visible for the full day in your current Doctor timezone.</p>
      </> : <>
        <strong>{people.length === 1 ? `${first.name} is celebrating a birthday today 🎂` : `${people.length} team members are celebrating birthdays today 🎂`}</strong>
        <p>All VAs can see today’s birthdays. VAs can send one cake sticker per birthday person.</p>
      </>}
      {employeeView && payload?.is_self_birthday && received.length > 0 && <div className="birthday-sticker-senders">
        <strong>🎂 Cake stickers from:</strong> {received.map(item => item.sender_name).join(', ')}
      </div>}
    </div>
    <div className="birthday-banner-people">
      {people.slice(0, 8).map(person => <div className="birthday-banner-person" key={person.id}>
        <BirthdayAvatar person={person}/>
        <div><strong>{person.name}</strong><span>{person.timezone}</span></div>
        {employeeView && !person.is_self && <button type="button" className="birthday-cake-button" disabled={person.sticker_sent || sending === person.id} onClick={() => sendSticker(person)} title="Send cake sticker">
          {person.sticker_sent ? '🎂 Sent' : <><Send size={13}/> 🎂</>}
        </button>}
      </div>)}
      {people.length > 8 && <span className="birthday-banner-more">+{people.length - 8}</span>}
    </div>
  </section>;
}
