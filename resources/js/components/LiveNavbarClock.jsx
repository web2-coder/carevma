import { useEffect, useMemo, useRef, useState } from 'react';
import { Clock3 } from 'lucide-react';
import api from '../api';

const DEFAULT_TIMEZONE = import.meta.env.VITE_DEFAULT_TIMEZONE || 'Asia/Karachi';
const RESYNC_MS = 5 * 60 * 1000;

function validTimezone(value) {
  const zone = String(value || '').trim();
  if (!zone) return null;
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: zone }).format(new Date(0));
    return zone;
  } catch {
    return null;
  }
}

export function timezoneForUser(user) {
  const assigned = validTimezone(user?.assigned_timezone);
  if (user?.role === 'employee') return assigned || validTimezone(user?.employee?.timezone) || DEFAULT_TIMEZONE;
  if (user?.role === 'client') return assigned || validTimezone(user?.client?.timezone) || DEFAULT_TIMEZONE;
  return assigned || validTimezone(user?.timezone) || DEFAULT_TIMEZONE;
}

function cityLabel(timezone) {
  const part = String(timezone || DEFAULT_TIMEZONE).split('/').pop() || 'UTC';
  return part.replaceAll('_', ' ');
}

export default function LiveNavbarClock({ user }) {
  const timezone = useMemo(() => timezoneForUser(user), [
    user?.id,
    user?.role,
    user?.assigned_timezone,
    user?.employee?.timezone,
    user?.client?.timezone,
    user?.timezone,
  ]);
  const [anchor, setAnchor] = useState(null);
  const [, tick] = useState(0);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    let retry = null;
    const sync = async () => {
      try {
        const { data } = await api.get('/time');
        const utcMs = Date.parse(data?.utc || '');
        if (!Number.isFinite(utcMs)) throw new Error('Invalid server time');
        if (mounted.current) setAnchor({ utcMs, perf: performance.now() });
      } catch {
        // Do not fall back to the workstation wall clock. A misconfigured Windows
        // clock was the original 12-hour offset bug. Keep the last trusted anchor
        // (or show Syncing time…) and retry the Laravel trusted-time endpoint.
        retry = window.setTimeout(sync, 15000);
      }
    };
    sync();
    const interval = window.setInterval(sync, RESYNC_MS);
    return () => {
      mounted.current = false;
      window.clearInterval(interval);
      if (retry) window.clearTimeout(retry);
    };
  }, [user?.id]);

  useEffect(() => {
    const id = window.setInterval(() => tick(v => v + 1), 1000);
    return () => window.clearInterval(id);
  }, []);

  if (!anchor) {
    return <div className="navbar-live-clock is-syncing" title={`Assigned timezone: ${timezone}`}>
      <span className="navbar-live-clock-icon"><Clock3 size={17}/><i/></span>
      <span className="navbar-live-clock-copy"><strong>Syncing time…</strong><small>{cityLabel(timezone)}</small></span>
      <span className="navbar-live-clock-zone">{timezone}</span>
    </div>;
  }

  const now = new Date(anchor.utcMs + Math.max(0, performance.now() - anchor.perf));
  const time = new Intl.DateTimeFormat('en-US', { timeZone: timezone, hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true }).format(now);
  const date = new Intl.DateTimeFormat('en-US', { timeZone: timezone, weekday: 'short', month: 'short', day: 'numeric' }).format(now);

  return <div className="navbar-live-clock is-live" title={`Current time · ${timezone}`}>
    <span className="navbar-live-clock-icon"><Clock3 size={17}/><i/></span>
    <span className="navbar-live-clock-copy"><strong>{time}</strong><small>{date} · {cityLabel(timezone)}</small></span>
    <span className="navbar-live-clock-zone">{timezone}</span>
  </div>;
}
