export const DEFAULT_TIMEZONE = import.meta.env.VITE_DEFAULT_TIMEZONE || 'Asia/Karachi';

export function validTimeZone(value, fallback = 'UTC') {
  const zone = String(value || '').trim();
  if (zone) {
    try {
      new Intl.DateTimeFormat('en-US', { timeZone: zone }).format(new Date(0));
      return zone;
    } catch {}
  }
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: fallback }).format(new Date(0));
    return fallback;
  } catch {
    return 'UTC';
  }
}

export function instant(value) {
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  if (value === null || value === undefined || value === '') return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value, timeZone = 'UTC', options = {}) {
  const date = instant(value);
  if (!date) return '—';
  return new Intl.DateTimeFormat('en-US', {
    timeZone: validTimeZone(timeZone, 'UTC'),
    year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit', hour12: true,
    ...options,
  }).format(date);
}

export function formatDate(value, timeZone = 'UTC', options = {}) {
  const date = instant(value);
  if (!date) return '—';
  return new Intl.DateTimeFormat('en-US', {
    timeZone: validTimeZone(timeZone, 'UTC'),
    year: 'numeric', month: 'short', day: '2-digit',
    ...options,
  }).format(date);
}

export function formatTime(value, timeZone = 'UTC', options = {}) {
  const date = instant(value);
  if (!date) return '—';
  return new Intl.DateTimeFormat('en-US', {
    timeZone: validTimeZone(timeZone, 'UTC'),
    hour: 'numeric', minute: '2-digit', hour12: true,
    ...options,
  }).format(date);
}

export function formatTime24(value, timeZone = 'UTC') {
  const date = instant(value);
  if (!date) return '';
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: validTimeZone(timeZone, 'UTC'),
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).format(date);
}

export function formatWeekday(value, timeZone = 'UTC') {
  const date = instant(value);
  if (!date) return '';
  return new Intl.DateTimeFormat('en-US', {
    timeZone: validTimeZone(timeZone, 'UTC'), weekday: 'long',
  }).format(date);
}

export function zonedNowParts(timeZone = 'UTC', value = new Date()) {
  const zone = validTimeZone(timeZone, 'UTC');
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: zone,
    year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'long',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(value);
  const map = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return {
    timeZone: zone,
    year: Number(map.year), month: Number(map.month), day: Number(map.day),
    weekday: map.weekday,
    hour: Number(map.hour), minute: Number(map.minute), second: Number(map.second),
    date: `${map.year}-${map.month}-${map.day}`,
  };
}

export function todayInZone(timeZone = 'UTC') {
  return zonedNowParts(timeZone).date;
}

export function weekdayFromDateOnly(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return '';
  const date = new Date(`${value}T12:00:00Z`);
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', weekday: 'long' }).format(date);
}

export function timezoneLabel(timeZone) {
  return validTimeZone(timeZone, 'UTC').replaceAll('_', ' ');
}
