import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Search, X } from 'lucide-react';
import api from '../api';

// One box in the topbar. Pages come from the sidebar nav (client side); every
// other group comes from /api/search, scoped to the signed-in role.
export default function TopbarSearch({ pages = [] }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [q, setQ] = useState('');
  const [groups, setGroups] = useState([]);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef(null);
  const inputRef = useRef(null);
  const seq = useRef(0);

  const term = q.trim();
  const ready = term.length >= 2;
  const needle = term.toLowerCase();
  const pageHits = ready ? pages.filter(p => p.label.toLowerCase().includes(needle)).slice(0, 5).map(p => ({ label: p.label, meta: p.to, url: p.to })) : [];
  const all = [...(pageHits.length ? [{ module: 'Pages', items: pageHits }] : []), ...groups];
  const flat = all.flatMap(g => g.items);

  useEffect(() => {
    if (!ready) { setGroups([]); setBusy(false); return undefined; }
    const id = ++seq.current;
    setBusy(true);
    const timer = setTimeout(() => {
      api.get('/search', { params: { q: term }, carevmaSilent: true })
        .then(r => { if (id === seq.current) setGroups(r.data?.groups || []); })
        .catch(() => { if (id === seq.current) setGroups([]); })
        .finally(() => { if (id === seq.current) setBusy(false); });
    }, 220);
    return () => clearTimeout(timer);
  }, [term, ready]);

  useEffect(() => { setActive(0); }, [term, groups]);
  useEffect(() => { setOpen(false); }, [location.pathname]);

  useEffect(() => {
    if (!open) return undefined;
    const close = event => { if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const go = item => {
    setOpen(false);
    setQ('');
    // Same page: the list reads its filter from the URL on mount only, so reload it there.
    if (item.url.split('?')[0] === location.pathname) window.location.assign(item.url);
    else navigate(item.url);
  };

  const onKeyDown = event => {
    if (event.key === 'Escape') { setOpen(false); inputRef.current?.blur(); return; }
    if (!flat.length) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); setActive(i => (i + 1) % flat.length); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(i => (i - 1 + flat.length) % flat.length); }
    else if (event.key === 'Enter') { event.preventDefault(); go(flat[active] || flat[0]); }
  };

  let index = -1;
  // On phones the box is an icon until focused; clicking anywhere on it focuses the input.
  return <div className="topbar-search" ref={rootRef} role="search" onClick={() => inputRef.current?.focus()}>
    <Search size={16} aria-hidden="true"/>
    <input
      ref={inputRef}
      value={q}
      onChange={event => { setQ(event.target.value); setOpen(true); }}
      onFocus={() => setOpen(true)}
      onKeyDown={onKeyDown}
      placeholder="Search the dashboard…"
      aria-label="Search the dashboard"
      autoComplete="off"
    />
    {q && <button type="button" onClick={() => { setQ(''); setGroups([]); }} aria-label="Clear search"><X size={14}/></button>}
    {open && ready && <div className="topbar-search-results" role="listbox" aria-label="Search results">
      {all.length ? all.map(group => <div className="topbar-search-group" key={group.module}>
        <div className="topbar-search-module eyebrow">{group.module}</div>
        {group.items.map(item => {
          index += 1;
          const i = index;
          return <button
            type="button"
            key={`${group.module}-${item.url}-${item.label}`}
            role="option"
            aria-selected={i === active}
            className={`topbar-search-item ${i === active ? 'is-active' : ''}`}
            onMouseEnter={() => setActive(i)}
            onClick={() => go(item)}
          >
            <span><strong>{item.label}</strong>{item.meta && <small>{item.meta}</small>}</span>
            <em>{group.module}</em>
          </button>;
        })}
      </div>) : <div className="topbar-search-empty">{busy ? 'Searching…' : `No results for “${term}”`}</div>}
    </div>}
  </div>;
}
