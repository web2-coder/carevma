import { useEffect, useMemo, useRef, useState } from 'react';
import { LoaderCircle, Search, X, CheckCircle2, AlertCircle, ChevronDown, Check } from 'lucide-react';
import { formatDateTime, formatTime24 } from '../timezone';


function normalizeMediaSrc(value) {
  const src = String(value || '').trim();
  if (!src) return '';
  if (/^(https?:|data:|blob:)/i.test(src)) return src;
  if (src.startsWith('/storage/')) return src;
  if (src.startsWith('storage/')) return `/${src}`;
  if (src.startsWith('/public/storage/')) return src.replace('/public', '');
  if (src.startsWith('public/storage/')) return `/${src.replace(/^public\//, '')}`;
  if (src.startsWith('/')) return src;
  // Laravel public-disk paths are sometimes saved without the /storage prefix.
  if (/^(employees|clients|profiles|uploads)\//i.test(src)) return `/storage/${src}`;
  return `/${src}`;
}

export function AvatarMedia({ src, alt = '', fallback = '?', className = '' }) {
  const [failed, setFailed] = useState(false);
  const normalizedSrc = normalizeMediaSrc(src);
  useEffect(() => setFailed(false), [normalizedSrc]);
  if (normalizedSrc && !failed) return <img className={className} src={normalizedSrc} alt={alt} onError={() => setFailed(true)}/>;
  return <span className={className} aria-label={alt || 'Profile placeholder'}>{fallback || '?'}</span>;
}

export function PageHeader({ eyebrow, title, description, actions }) {
  return <div className="page-header">
    <div><div className="eyebrow">{eyebrow}</div><h1>{title}</h1>{description && <p>{description}</p>}</div>
    {actions && <div className="page-actions">{actions}</div>}
  </div>;
}

export function Card({ children, className = '', title, subtitle, actions }) {
  return <section className={`card ${className}`}>
    {(title || actions) && <div className="card-head"><div><h3>{title}</h3>{subtitle && <p>{subtitle}</p>}</div>{actions}</div>}
    {children}
  </section>;
}

export function MetricCard({ label, value, hint, icon: Icon, live = false }) {
  const liveActive = live && Number(value || 0) > 0;

  return <div className={`metric-card${live ? ' metric-card-live' : ''}${liveActive ? ' is-live-active' : live ? ' is-live-idle' : ''}`}>
    <div className="metric-icon">
      {live && <><span className="metric-live-ring metric-live-ring-one"/><span className="metric-live-ring metric-live-ring-two"/></>}
      {Icon && <Icon size={20}/>}
      {live && <span className="metric-live-dot" aria-label="Live"/>}
    </div>
    <div className="metric-copy">
      <span>{label}{live && <em className="metric-live-label"><i/>LIVE</em>}</span>
      <strong key={live ? String(value ?? 0) : 'metric-value'} className={live ? 'metric-live-value' : ''}>{value ?? '—'}</strong>
      {hint && <small>{hint}</small>}
    </div>
  </div>;
}

export function Button({ children, variant = 'primary', loading, icon: Icon, className = '', ...props }) {
  return <button className={`btn btn-${variant} ${className}`} disabled={loading || props.disabled} {...props}>
    {loading ? <LoaderCircle size={16} className="spin"/> : Icon ? <Icon size={16}/> : null}{children}
  </button>;
}

export function Input({ label, error, hint, ...props }) {
  return <label className="field"><span>{label}</span><input {...props}/>{hint && <small>{hint}</small>}{error && <em>{error}</em>}</label>;
}
export function Select({ label, error, children, ...props }) {
  return <label className="field"><span>{label}</span><select {...props}>{children}</select>{error && <em>{error}</em>}</label>;
}

export function SearchableSelect({
  label, value, onChange, options = [], placeholder = 'Select', searchPlaceholder = 'Search…',
  disabled = false, error, emptyText = 'No matching results',
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const rootRef = useRef(null);
  const normalizedValue = String(value ?? '');
  const selected = options.find(option => String(option.value ?? '') === normalizedValue);
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter(option => `${option.label || ''} ${option.searchText || ''} ${option.meta || ''}`.toLowerCase().includes(q));
  }, [options, query]);

  useEffect(() => {
    if (!open) return undefined;
    const close = event => { if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false); };
    const escape = event => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', escape); };
  }, [open]);

  const choose = option => {
    onChange?.(String(option.value ?? ''));
    setQuery('');
    setOpen(false);
  };

  return <div className={`field searchable-select-field ${disabled ? 'is-disabled' : ''}`} ref={rootRef}>
    <span>{label}</span>
    <button type="button" className={`searchable-select-trigger ${open ? 'is-open' : ''}`} disabled={disabled} onClick={() => setOpen(current => !current)} aria-haspopup="listbox" aria-expanded={open}>
      <span className={selected ? '' : 'is-placeholder'}>{selected?.label || placeholder}</span><ChevronDown size={16}/>
    </button>
    {open && <div className="searchable-select-popover">
      <div className="searchable-select-search"><Search size={15}/><input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder={searchPlaceholder}/>{query && <button type="button" onClick={() => setQuery('')} aria-label="Clear search"><X size={14}/></button>}</div>
      <div className="searchable-select-options" role="listbox">
        {filtered.length ? filtered.map(option => {
          const active = String(option.value ?? '') === normalizedValue;
          return <button type="button" key={`${option.value}-${option.label}`} className={`searchable-select-option ${active ? 'is-selected' : ''}`} onClick={() => choose(option)} role="option" aria-selected={active}>
            <span><strong>{option.label}</strong>{option.meta && <small>{option.meta}</small>}</span>{active && <Check size={15}/>}
          </button>;
        }) : <div className="searchable-select-empty">{emptyText}</div>}
      </div>
    </div>}
    {error && <em>{error}</em>}
  </div>;
}
export function SearchableMultiSelect({
  label, values = [], onChange, options = [], placeholder = 'Select', searchPlaceholder = 'Search…',
  disabled = false, error, emptyText = 'No matching results',
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const rootRef = useRef(null);
  const normalizedValues = (Array.isArray(values) ? values : []).map(value => String(value ?? ''));
  const selected = options.filter(option => normalizedValues.includes(String(option.value ?? '')));
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter(option => `${option.label || ''} ${option.searchText || ''} ${option.meta || ''}`.toLowerCase().includes(q));
  }, [options, query]);

  useEffect(() => {
    if (!open) return undefined;
    const close = event => { if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false); };
    const escape = event => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', escape); };
  }, [open]);

  const toggle = option => {
    const id = String(option.value ?? '');
    const next = normalizedValues.includes(id)
      ? normalizedValues.filter(value => value !== id)
      : [...normalizedValues, id];
    onChange?.(next);
  };

  const summary = selected.length
    ? selected.length === 1 ? selected[0].label : `${selected.length} Doctors / Clients selected`
    : placeholder;

  return <div className={`field searchable-select-field searchable-multi-select-field ${disabled ? 'is-disabled' : ''}`} ref={rootRef}>
    <span>{label}</span>
    <button type="button" className={`searchable-select-trigger ${open ? 'is-open' : ''}`} disabled={disabled} onClick={() => setOpen(current => !current)} aria-haspopup="listbox" aria-expanded={open}>
      <span className={selected.length ? '' : 'is-placeholder'}>{summary}</span><ChevronDown size={16}/>
    </button>
    {open && <div className="searchable-select-popover searchable-multi-select-popover">
      <div className="searchable-select-search"><Search size={15}/><input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder={searchPlaceholder}/>{query && <button type="button" onClick={() => setQuery('')} aria-label="Clear search"><X size={14}/></button>}</div>
      <div className="searchable-select-options" role="listbox" aria-multiselectable="true">
        {filtered.length ? filtered.map(option => {
          const active = normalizedValues.includes(String(option.value ?? ''));
          return <button type="button" key={`${option.value}-${option.label}`} className={`searchable-select-option ${active ? 'is-selected' : ''}`} onClick={() => toggle(option)} role="option" aria-selected={active}>
            <span><strong>{option.label}</strong>{option.meta && <small>{option.meta}</small>}</span><span className={`searchable-multi-check ${active ? 'is-selected' : ''}`}>{active && <Check size={14}/>}</span>
          </button>;
        }) : <div className="searchable-select-empty">{emptyText}</div>}
      </div>
      <div className="searchable-multi-footer"><small>{selected.length} selected</small><button type="button" onClick={() => { onChange?.([]); setQuery(''); }}>Clear</button><button type="button" onClick={() => setOpen(false)}>Done</button></div>
    </div>}
    {error && <em>{error}</em>}
  </div>;
}

export function Textarea({ label, error, ...props }) {
  return <label className="field"><span>{label}</span><textarea {...props}/>{error && <em>{error}</em>}</label>;
}

export function SearchBox({ value, onChange, placeholder = 'Search…' }) {
  return <div className="search-box"><Search size={17}/><input value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder}/>{value && <button onClick={() => onChange('')}><X size={15}/></button>}</div>;
}

export function Badge({ children, tone = 'neutral' }) { return <span className={`badge badge-${tone}`}>{children}</span>; }
export function statusTone(status = '') {
  const s = String(status).toLowerCase();
  if (['active','enabled','approved','paid','issued'].includes(s)) return 'success';
  if (['pending','draft','late'].includes(s)) return 'warning';
  if (['rejected','disabled','inactive','void'].includes(s)) return 'danger';
  return 'neutral';
}

export function EmptyState({ title = 'Nothing here yet', description = 'No records match the current view.' }) {
  return <div className="empty-state"><div className="empty-mark">✦</div><h3>{title}</h3><p>{description}</p></div>;
}
export function Loading() { return <div className="screen-loader"><LoaderCircle className="spin"/><span>Loading CareVMA…</span></div>; }
export function Notice({ type = 'success', children, onClose }) {
  return <div className={`notice notice-${type}`}>{type === 'success' ? <CheckCircle2 size={18}/> : <AlertCircle size={18}/>}<span>{children}</span>{onClose && <button onClick={onClose}><X size={15}/></button>}</div>;
}

export function Modal({ open, title, children, onClose, footer, wide = false }) {
  if (!open) return null;
  return <div className="modal-backdrop" onMouseDown={e => e.target === e.currentTarget && onClose()}>
    <div className={`modal ${wide ? 'modal-wide' : ''}`}><div className="modal-head"><h2>{title}</h2><button onClick={onClose}><X/></button></div><div className="modal-body">{children}</div>{footer && <div className="modal-foot">{footer}</div>}</div>
  </div>;
}

function tableSearchText(value, depth = 0) {
  if (value === null || value === undefined || depth > 3) return '';
  if (['string', 'number', 'boolean'].includes(typeof value)) return String(value);
  if (Array.isArray(value)) return value.map(item => tableSearchText(item, depth + 1)).join(' ');
  if (typeof value === 'object') return Object.values(value).map(item => tableSearchText(item, depth + 1)).join(' ');
  return '';
}

function primitiveFilterValue(value) {
  if (value === null || value === undefined || value === '') return null;
  if (['string', 'number', 'boolean'].includes(typeof value)) return String(value);
  return null;
}

export function Table({
  columns,
  rows,
  rowKey = 'id',
  emptyTitle,
  emptyDescription,
  searchable = true,
  localPagination = true,
  defaultPageSize = 10,
}) {
  const safeRows = Array.isArray(rows) ? rows : [];
  const [query, setQuery] = useState('');
  const [filterColumn, setFilterColumn] = useState('');
  const [filterValue, setFilterValue] = useState('');
  const [pageSize, setPageSize] = useState(defaultPageSize);
  const [page, setPage] = useState(1);

  const filterableColumns = useMemo(() => {
    return (columns || []).map(column => {
      if (!column?.key || ['action', 'actions'].includes(String(column.key).toLowerCase())) return null;
      const values = [...new Set(safeRows.map(row => primitiveFilterValue(row?.[column.key])).filter(value => value !== null))];
      if (values.length < 2 || values.length > 40) return null;
      return { key: column.key, label: column.label || column.key, values: values.sort((a, b) => a.localeCompare(b, undefined, { numeric: true })) };
    }).filter(Boolean);
  }, [columns, safeRows]);

  const activeFilter = filterableColumns.find(column => column.key === filterColumn);

  const filteredRows = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();
    return safeRows.filter(row => {
      if (normalizedQuery && !tableSearchText(row).toLowerCase().includes(normalizedQuery)) return false;
      if (filterColumn && filterValue) {
        const value = primitiveFilterValue(row?.[filterColumn]);
        if (value !== filterValue) return false;
      }
      return true;
    });
  }, [safeRows, query, filterColumn, filterValue]);

  useEffect(() => {
    setPage(1);
  }, [query, filterColumn, filterValue, pageSize, safeRows.length]);

  useEffect(() => {
    if (!filterColumn || filterableColumns.some(column => column.key === filterColumn)) return;
    setFilterColumn('');
    setFilterValue('');
  }, [filterColumn, filterableColumns]);

  const total = filteredRows.length;
  const effectivePageSize = Math.max(1, Number(pageSize) || 10);
  // Avoid stacking a second pager on API-paginated pages that already load one normal page (usually <= 10 rows).
  // Local pagination automatically activates for larger in-memory tables.
  const useLocalPager = localPagination && safeRows.length > defaultPageSize;
  const lastPage = useLocalPager ? Math.max(1, Math.ceil(total / effectivePageSize)) : 1;
  const currentPage = Math.min(page, lastPage);
  const startIndex = useLocalPager ? (currentPage - 1) * effectivePageSize : 0;
  const visibleRows = useLocalPager ? filteredRows.slice(startIndex, startIndex + effectivePageSize) : filteredRows;
  const from = total ? startIndex + 1 : 0;
  const to = total ? Math.min(startIndex + visibleRows.length, total) : 0;

  if (!safeRows.length) return <EmptyState title={emptyTitle} description={emptyDescription}/>;

  const resetTools = () => {
    setQuery('');
    setFilterColumn('');
    setFilterValue('');
    setPage(1);
  };

  return <div className="smart-table">
    <div className="smart-table-tools">
      <div className="smart-table-tools-left">
        {searchable && <SearchBox value={query} onChange={setQuery} placeholder="Search this table…"/>}
        {filterableColumns.length > 0 && <select className="table-filter-select" value={filterColumn} onChange={e => { setFilterColumn(e.target.value); setFilterValue(''); }} aria-label="Filter table column">
          <option value="">Filter column</option>
          {filterableColumns.map(column => <option key={column.key} value={column.key}>{column.label}</option>)}
        </select>}
        {activeFilter && <select className="table-filter-select" value={filterValue} onChange={e => setFilterValue(e.target.value)} aria-label={`Filter ${activeFilter.label}`}>
          <option value="">All {activeFilter.label}</option>
          {activeFilter.values.map(value => <option key={value} value={value}>{value}</option>)}
        </select>}
        {(query || filterColumn || filterValue) && <button type="button" className="table-reset-btn" onClick={resetTools}>Reset</button>}
      </div>
      <div className="smart-table-tools-right">
        <span className="table-result-count">{total === safeRows.length ? `${total} records` : `${total} of ${safeRows.length} records`}</span>
        {useLocalPager && <label className="table-page-size"><span>Rows</span><select value={effectivePageSize} onChange={e => setPageSize(Number(e.target.value))}><option value={10}>10</option><option value={25}>25</option><option value={50}>50</option><option value={100}>100</option></select></label>}
      </div>
    </div>

    {visibleRows.length ? <div className="table-wrap"><table><thead><tr>{columns.map(c => <th key={c.key}>{c.label}</th>)}</tr></thead><tbody>{visibleRows.map((row, index) => <tr key={row?.[rowKey] ?? `${currentPage}-${index}`}>{columns.map(c => <td key={c.key} data-label={c.label}>{c.render ? c.render(row) : row?.[c.key] ?? '—'}</td>)}</tr>)}</tbody></table></div> : <EmptyState title="No matching records" description="Change the search or filter to see more results."/>}

    {useLocalPager && total > effectivePageSize && <div className="smart-table-pagination">
      <span>Showing {from}–{to} of {total}</span>
      <div className="smart-page-buttons">
        <button type="button" disabled={currentPage <= 1} onClick={() => setPage(1)} aria-label="First page">«</button>
        <button type="button" disabled={currentPage <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}>Previous</button>
        <span>Page <strong>{currentPage}</strong> of {lastPage}</span>
        <button type="button" disabled={currentPage >= lastPage} onClick={() => setPage(value => Math.min(lastPage, value + 1))}>Next</button>
        <button type="button" disabled={currentPage >= lastPage} onClick={() => setPage(lastPage)} aria-label="Last page">»</button>
      </div>
    </div>}
  </div>;
}

function paginationWindow(current, last) {
  const pages = [];
  const start = Math.max(1, current - 2);
  const end = Math.min(last, current + 2);
  if (start > 1) pages.push(1);
  if (start > 2) pages.push('left-ellipsis');
  for (let page = start; page <= end; page += 1) pages.push(page);
  if (end < last - 1) pages.push('right-ellipsis');
  if (end < last) pages.push(last);
  return pages;
}

export function Pagination({ meta, onPage }) {
  if (!meta || Number(meta.last_page || 1) <= 1) return null;
  const current = Number(meta.current_page || 1);
  const last = Number(meta.last_page || 1);
  const pages = paginationWindow(current, last);
  const from = meta.from ?? null;
  const to = meta.to ?? null;
  const total = meta.total ?? null;

  return <div className="pagination pagination-enhanced">
    <span>{from !== null && to !== null && total !== null ? `Showing ${from}–${to} of ${total}` : `Page ${current} of ${last}`}</span>
    <div className="pagination-buttons">
      <Button variant="ghost" disabled={current <= 1} onClick={() => onPage(current - 1)}>Previous</Button>
      <div className="pagination-pages">{pages.map(item => typeof item === 'number' ? <button type="button" key={item} className={item === current ? 'active' : ''} onClick={() => onPage(item)}>{item}</button> : <span key={item}>…</span>)}</div>
      <Button variant="ghost" disabled={current >= last} onClick={() => onPage(current + 1)}>Next</Button>
    </div>
  </div>;
}

export const money = value => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(value || 0));
export const durationHM = value => {
  const total = Math.max(0, Math.round(Number(value || 0)));
  const hours = Math.floor(total / 60);
  const minutes = total % 60;
  if (!hours) return `${minutes}m`;
  return minutes ? `${hours}h ${minutes}m` : `${hours}h`;
};
export const mins = value => {
  const total = Math.max(0, Math.round(Number(value || 0)));
  if (total < 60) return `${total} min`;
  const hours = Math.floor(total / 60);
  const minutes = total % 60;
  return minutes ? `${hours}h ${minutes}m` : `${hours}h`;
};
export const dateTime = (value, timeZone = 'UTC') => formatDateTime(value, timeZone);

export const timeValue = (value, timeZone = 'UTC') => formatTime24(value, timeZone);
