import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Clock3, Globe2, MapPin, Search } from 'lucide-react';
import cityTimezones from 'city-timezones';

function isValidTimezone(value) {
  if (!value) return false;
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: value }).format(new Date());
    return true;
  } catch {
    return false;
  }
}

function zoneCity(zone = '') {
  const parts = String(zone).split('/');
  return (parts.at(-1) || zone).replaceAll('_', ' ');
}

function zoneRegion(zone = '') {
  const parts = String(zone).split('/');
  if (parts.length < 2) return 'Global';
  return parts.slice(0, -1).join(' / ').replaceAll('_', ' ');
}

function timeInfo(timeZone, now = new Date()) {
  if (!isValidTimezone(timeZone)) return null;
  try {
    const time = new Intl.DateTimeFormat('en-US', {
      timeZone,
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    }).format(now);

    let offset = '';
    try {
      offset = new Intl.DateTimeFormat('en-US', {
        timeZone,
        timeZoneName: 'shortOffset',
        hour: '2-digit',
      }).formatToParts(now).find(part => part.type === 'timeZoneName')?.value || '';
    } catch {
      offset = '';
    }

    return { time, offset };
  } catch {
    return null;
  }
}

function uniqueByTimezone(items) {
  const seen = new Set();
  return items.filter(item => {
    if (!item?.timezone || seen.has(item.timezone)) return false;
    seen.add(item.timezone);
    return true;
  });
}

export default function TimezonePicker({
  label = 'Timezone *',
  value,
  onChange,
  onCitySelect,
  hint = 'Search any city, country or IANA timezone. You can also enter a timezone manually.',
}) {
  const wrapRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [manual, setManual] = useState(false);
  const [manualValue, setManualValue] = useState(value || '');
  const [now, setNow] = useState(() => new Date());

  useEffect(() => setManualValue(value || ''), [value]);
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 30000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    const close = event => {
      if (wrapRef.current && !wrapRef.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const allZones = useMemo(() => {
    let zones = [];
    try {
      zones = Intl.supportedValuesOf?.('timeZone') || [];
    } catch {
      zones = [];
    }
    if (!zones.length) {
      zones = [...new Set((cityTimezones.cityMapping || []).map(item => item.timezone).filter(Boolean))];
    }
    if (!zones.includes('UTC')) zones.unshift('UTC');
    return zones;
  }, []);

  const results = useMemo(() => {
    const term = query.trim();
    const lower = term.toLowerCase();
    const cityMatches = term.length >= 2
      ? uniqueByTimezone((cityTimezones.findFromCityStateProvince?.(term) || []).slice(0, 18)).map(item => ({
          type: 'city',
          timezone: item.timezone,
          city: item.city,
          province: item.province,
          country: item.country,
        }))
      : [];

    const zoneMatches = allZones
      .filter(zone => !lower || zone.toLowerCase().includes(lower) || zoneCity(zone).toLowerCase().includes(lower))
      .slice(0, term ? 24 : 18)
      .map(zone => ({ type: 'zone', timezone: zone, city: zoneCity(zone), country: zoneRegion(zone) }));

    const seen = new Set();
    return [...cityMatches, ...zoneMatches].filter(item => {
      const key = `${item.timezone}|${item.city || ''}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).slice(0, 30);
  }, [query, allZones]);

  const selectedInfo = timeInfo(value, now);
  const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

  const choose = item => {
    onChange?.(item.timezone);
    if (item.type === 'city') onCitySelect?.(item);
    setQuery('');
    setOpen(false);
    setManual(false);
  };

  const applyManual = () => {
    if (!isValidTimezone(manualValue)) return;
    onChange?.(manualValue.trim());
    setOpen(false);
    setManual(false);
    setQuery('');
  };

  return <div className="field timezone-field" ref={wrapRef}>
    <span>{label}</span>
    <button type="button" className="timezone-trigger" onClick={() => setOpen(v => !v)}>
      <span className="timezone-trigger-icon"><Globe2 size={16}/></span>
      <span className="timezone-trigger-copy">
        <strong>{value || 'Select timezone'}</strong>
        <small>{value ? zoneCity(value) : 'Search worldwide'}</small>
      </span>
      <ChevronDown size={16}/>
    </button>

    {value && selectedInfo && <div className="timezone-live">
      <Clock3 size={14}/>
      <span><strong>{selectedInfo.time}</strong>{selectedInfo.offset && ` · ${selectedInfo.offset}`}</span>
    </div>}

    {hint && <small>{hint}</small>}

    {open && <div className="timezone-popover">
      <div className="timezone-search"><Search size={15}/><input autoFocus value={query} onChange={e => setQuery(e.target.value)} placeholder="Search Lahore, London, New York, Asia/Karachi…"/></div>
      <button type="button" className="timezone-browser" onClick={() => choose({ type: 'zone', timezone: browserZone, city: zoneCity(browserZone), country: zoneRegion(browserZone) })}>
        <MapPin size={15}/><span>Use my current timezone</span><strong>{browserZone}</strong>
      </button>

      {!manual && <div className="timezone-results">
        {results.map((item, index) => {
          const info = timeInfo(item.timezone, now);
          const location = item.type === 'city'
            ? [item.city, item.province, item.country].filter(Boolean).join(', ')
            : `${item.city} · ${item.country}`;
          return <button type="button" key={`${item.timezone}-${item.city}-${index}`} className={`timezone-option ${value === item.timezone ? 'selected' : ''}`} onClick={() => choose(item)}>
            <span><strong>{location}</strong><small>{item.timezone}</small></span>
            <span className="timezone-option-time">{info?.time?.replace(/^[A-Za-z]{3},\s*/, '') || ''}{value === item.timezone && <Check size={14}/>}</span>
          </button>;
        })}
        {!results.length && <div className="timezone-empty">No city/timezone match. Use manual IANA entry below.</div>}
      </div>}

      {manual ? <div className="timezone-manual">
        <input value={manualValue} onChange={e => setManualValue(e.target.value)} placeholder="e.g. Asia/Karachi"/>
        <div className={`timezone-validation ${isValidTimezone(manualValue) ? 'valid' : 'invalid'}`}>
          {isValidTimezone(manualValue) ? `Valid timezone · ${timeInfo(manualValue, now)?.time || ''}` : 'Enter a valid IANA timezone.'}
        </div>
        <div className="timezone-manual-actions">
          <button type="button" className="btn btn-ghost" onClick={() => setManual(false)}>Back</button>
          <button type="button" className="btn btn-primary" disabled={!isValidTimezone(manualValue)} onClick={applyManual}>Use timezone</button>
        </div>
      </div> : <button type="button" className="timezone-manual-link" onClick={() => setManual(true)}>Enter timezone manually</button>}
    </div>}
  </div>;
}
