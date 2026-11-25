import { useEffect, useLayoutEffect, useMemo, useRef, useState, Fragment } from 'react';
import { can, ADMIN_ROLES, referenceModule } from '../permissions';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard, Users, UserRoundCog, CalendarDays, Clock3, FileCheck2, TimerReset,
  ReceiptText, SlidersHorizontal, Bell, UserCircle2, Settings, LogOut, Menu, X, BriefcaseBusiness,
  Stethoscope, ClipboardList, ChevronDown, ChevronRight, PanelLeftClose, PanelLeftOpen, Activity, CircleDollarSign, BookOpenText, ScrollText, Settings2, ShieldCheck, LifeBuoy, MonitorCog, Sun, Moon
} from 'lucide-react';
import { NotificationIcon } from '../notificationIcon';
import { useAuth } from '../context/AuthContext';
import api from '../api';
import { subscribeToCareVmaNotifications } from '../pusher';
import { installNotificationSoundUnlock, playNotificationSoundOnce } from '../notificationSound';
import BrandLogo from './BrandLogo';
import LiveNavbarClock from './LiveNavbarClock';
import {activeThemeMode, setPersonalThemeMode, cachePersonalMode} from '../branding';
import TopbarSearch from './TopbarSearch';
import BirthdayPopup from './BirthdayPopup';
import { AvatarMedia, Loading } from './ui';
import { formatDate } from '../timezone';
import { emitToast } from '../feedback';
import '../../css/live-workforce-badge.css';
import '../../css/carevma-brand-clock.css';
import '../../css/birthday-popup.css';
import '../../css/profile-onboarding-lock.css';
import '../../css/admin-avatar-media.css';

const managementBase = role => role === 'hr' ? '/hr' : '/admin';


function profileIncomplete(user) {
  if (user?.role !== 'employee') return false;
  const value = user?.employee?.profile_completed;
  return value === false || value === 0 || value === '0';
}

const ADMIN_CONFIG = [
  ['Departments','departments'], ['Roles','roles'], ['Employment Types','employment-types'],
  ['Shift Types','shift-types'], ['Designations','designations'], ['Employee Status','employee-statuses'],
  ['Expertise','expertises'], ['Reporting Managers','reporting-managers'], ['Services','services'], ['Contract Types','contracts'],
];


// Staff navigation is driven by module grants (Roles & Permissions). Shared modules live under the
// role's own prefix; admin-only modules keep their /admin paths and appear for any role granted them.
function staffNav(user) {
  const admin = ADMIN_ROLES.includes(user.role);
  const base = admin ? '/admin' : '/hr';
  const allow = module => can(user, module);
  const configItems = ADMIN_CONFIG.filter(([, slug]) => allow(referenceModule(slug)));
  return [
    { group: 'Overview', label: 'Dashboard', to: `${base}/dashboard`, icon: LayoutDashboard },
    allow('live-users') && { group: 'Overview', label: 'Live Workforce', to: `${base}/live-users`, icon: Activity },
    allow('employees') && { group: 'People', label: 'Employees', to: `${base}/employees`, icon: Users },
    allow('hr-team') && { group: 'People', label: 'HR Team', to: '/admin/hr-team', icon: UserRoundCog },
    allow('clients') && { group: 'People', label: 'Clients', to: '/admin/clients', icon: Stethoscope },
    allow('schedules') && { group: 'Time', label: 'Schedules', to: `${base}/schedules`, icon: CalendarDays },
    allow('attendance') && { group: 'Time', label: 'Attendance', to: `${base}/attendance`, icon: Clock3 },
    allow('change-requests') && { group: 'Time', label: 'Change Requests', to: `${base}/change-requests`, icon: FileCheck2 },
    allow('overtime') && { group: 'Time', label: 'Overtime Review', to: `${base}/overtime`, icon: TimerReset },
    allow('invoices') && { group: 'Billing', label: 'Invoices', to: '/admin/invoices', icon: ReceiptText },
    allow('company-finance') && { group: 'Billing', label: 'Company Finance', to: '/admin/company-finance', icon: CircleDollarSign },
    allow('invoice-settings') && { group: 'Billing', label: 'Invoice Settings', to: '/admin/invoice-settings', icon: Settings2 },
    allow('notebook') && { group: 'Documents', label: 'Notebook', to: `${base}/notebook`, icon: BookOpenText },
    allow('activity-logs') && { group: 'Logs', label: 'Activity Logs', to: '/admin/activity-logs', icon: ScrollText },
    configItems.length > 0 && {
      group: 'Setup', label: admin ? 'Configuration' : 'HR Configuration', icon: SlidersHorizontal,
      children: configItems.map(([label, slug]) => ({ label, to: `${base}/options/${slug}` })),
    },
    admin && { group: 'Setup', label: 'Roles & Permissions', to: '/admin/roles-permissions', icon: ShieldCheck },
    { group: 'Help', label: 'Docs', to: `${base}/docs`, icon: LifeBuoy },   // documentation is never gated
    { group: 'Account', label: 'Notifications', to: `${base}/notifications`, icon: Bell },
    allow('branding') && { group: 'Setup', label: 'Branding', to: `${base}/branding`, icon: MonitorCog },
    { group: 'Account', label: 'My Account', to: `${base}/account`, icon: UserCircle2 },
  ].filter(Boolean);
}

function navFor(user) {
  if (!user) return [];
  if ([...ADMIN_ROLES, 'hr'].includes(user.role)) return staffNav(user);
  if (user.role === 'employee') {
    if (profileIncomplete(user)) {
      return [
        { label: 'Complete My Profile', to: '/employee/profile', icon: UserCircle2 },
      ];
    }

    return [
      { label: 'Dashboard', to: '/employee/dashboard', icon: LayoutDashboard },
      { label: 'My Schedule', to: '/employee/schedule', icon: CalendarDays },
      { label: 'My Attendance', to: '/employee/attendance', icon: Clock3 },
      { label: 'My Profile', to: '/employee/profile', icon: UserCircle2 },
      { label: 'Shared Documents', to: '/employee/documents', icon: BookOpenText },
      { label: 'Notifications', to: '/employee/notifications', icon: Bell },
      { label: 'Settings', to: '/employee/settings', icon: Settings },
      { label: 'Help Guide', to: '/employee/docs', icon: LifeBuoy },
    ];
  }
  if (user.role === 'client') {
    return [
      { label: 'Assigned Team', to: '/doctor/dashboard', icon: Users },
      { label: 'Shared Documents', to: '/doctor/documents', icon: BookOpenText },
      { label: 'Notifications', to: '/doctor/notifications', icon: Bell },
      { label: 'My Profile', to: '/doctor/profile', icon: UserCircle2 },
      { label: 'Help Guide', to: '/doctor/docs', icon: LifeBuoy },
    ];
  }
  return [];
}

function RouteLoadingOutlet() {
  const location = useLocation();
  const [loading, setLoading] = useState(true);
  const loadingRef = useRef(true);
  const pendingRef = useRef(new Set());
  const sawRequestRef = useRef(false);
  const startedAtRef = useRef(Date.now());
  const timersRef = useRef({ idle: null, finish: null, max: null });

  const clearTimer = name => {
    if (timersRef.current[name]) {
      clearTimeout(timersRef.current[name]);
      timersRef.current[name] = null;
    }
  };

  const finish = () => {
    if (!loadingRef.current) return;
    clearTimer('finish');
    const elapsed = Date.now() - startedAtRef.current;
    const wait = Math.max(80, 220 - elapsed);
    timersRef.current.finish = setTimeout(() => {
      loadingRef.current = false;
      setLoading(false);
      clearTimer('max');
    }, wait);
  };

  useEffect(() => {
    const onStart = event => {
      if (!loadingRef.current) return;
      const id = String(event?.detail?.id || '');
      if (!id) return;
      sawRequestRef.current = true;
      pendingRef.current.add(id);
      clearTimer('idle');
      clearTimer('finish');
    };
    const onEnd = event => {
      if (!loadingRef.current) return;
      const id = String(event?.detail?.id || '');
      if (!id || !pendingRef.current.has(id)) return;
      pendingRef.current.delete(id);
      if (sawRequestRef.current && pendingRef.current.size === 0) finish();
    };
    const onPaginationNavigation = event => {
      const server = event?.detail?.server !== false;

      clearTimer('idle');
      clearTimer('finish');
      clearTimer('max');
      pendingRef.current.clear();
      sawRequestRef.current = false;
      startedAtRef.current = Date.now();
      loadingRef.current = true;
      setLoading(true);

      if (server) {
        // Server pagination normally starts a GET request immediately after the
        // page state changes. Wait for that request to finish, with a small
        // fallback in case a page does not need to refetch for any reason.
        timersRef.current.idle = setTimeout(() => {
          if (!sawRequestRef.current) finish();
        }, 700);
        timersRef.current.max = setTimeout(() => finish(), 10000);
        return;
      }

      // Local/in-memory pagination has no network request. Keep the existing
      // CareVMA skeleton visible briefly so the page transition still reads as
      // an intentional data-page change rather than an abrupt table jump.
      timersRef.current.idle = setTimeout(() => finish(), 260);
      timersRef.current.max = setTimeout(() => finish(), 1200);
    };

    window.addEventListener('carevma:page-request-start', onStart);
    window.addEventListener('carevma:page-request-end', onEnd);
    window.addEventListener('carevma:pagination-navigation', onPaginationNavigation);
    return () => {
      window.removeEventListener('carevma:page-request-start', onStart);
      window.removeEventListener('carevma:page-request-end', onEnd);
      window.removeEventListener('carevma:pagination-navigation', onPaginationNavigation);
    };
  }, []);

  useLayoutEffect(() => {
    clearTimer('idle');
    clearTimer('finish');
    clearTimer('max');
    pendingRef.current.clear();
    sawRequestRef.current = false;
    startedAtRef.current = Date.now();
    loadingRef.current = true;
    setLoading(true);

    timersRef.current.idle = setTimeout(() => {
      if (!sawRequestRef.current) finish();
    }, 260);
    timersRef.current.max = setTimeout(() => finish(), 10000);

    return () => {
      clearTimer('idle');
      clearTimer('finish');
      clearTimer('max');
    };
  }, [location.pathname]);

  return <div className="route-loading-stage">
    <div className={`route-loading-content ${loading ? 'is-loading' : ''}`} aria-hidden={loading ? 'true' : undefined}>
      <Outlet/>
    </div>
    {loading && <div className="route-loading-overlay"><Loading/></div>}
  </div>;
}

function NavItem({ item, collapsed, mobileClose, liveCount = 0 }) {
  const location = useLocation();
  const [open, setOpen] = useState(() => item.children?.some(c => location.pathname.startsWith(c.to)) ?? false);
  const Icon = item.icon;
  if (item.children) {
    return <div className="nav-group">
      <button className={`nav-link nav-parent ${open ? 'is-open' : ''}`} onClick={() => setOpen(v => !v)} title={collapsed ? item.label : undefined}>
        <span className="nav-icon">{Icon && <Icon size={19}/>}</span>{!collapsed && <><span>{item.label}</span>{open ? <ChevronDown size={15}/> : <ChevronRight size={15}/>}</>}
      </button>
      {open && !collapsed && <div className="nav-children">{item.children.map(child => <NavLink key={child.to} to={child.to} onClick={mobileClose} className={({isActive}) => `nav-child ${isActive ? 'active' : ''}`}>{child.label}</NavLink>)}</div>}
    </div>;
  }
  const isLiveWorkforce = item.label === 'Live Workforce';
  return <NavLink to={item.to} onClick={mobileClose} title={collapsed ? item.label : undefined} className={({isActive}) => `nav-link ${isActive ? 'active' : ''}`}>
    <span className="nav-icon nav-icon-with-badge">{Icon && <Icon size={19}/>} {isLiveWorkforce && liveCount > 0 && collapsed && <span className="live-count-badge live-count-badge-mini">{liveCount > 99 ? '99+' : liveCount}</span>}</span>
    {!collapsed && <span>{item.label}</span>}
    {isLiveWorkforce && liveCount > 0 && !collapsed && <span className="live-count-badge"><i/> {liveCount > 99 ? '99+' : liveCount}</span>}
  </NavLink>;
}

export default function AppShell() {
  const { user, logout } = useAuth();
  const [personalAppearance, setPersonalAppearance] = useState(() => activeThemeMode());
  const [appearanceSaving, setAppearanceSaving] = useState(false);
  useEffect(() => {
    const sync = () => setPersonalAppearance(activeThemeMode());
    window.addEventListener('carevma:theme-changed',sync);
    return () => window.removeEventListener('carevma:theme-changed',sync);
  }, []);
  const togglePersonalAppearance = async () => {
    if (appearanceSaving || !user?.id) return;
    const previous = activeThemeMode();
    const next = previous === 'dark' ? 'light' : 'dark';
    setPersonalThemeMode(next);
    setPersonalAppearance(next);
    setAppearanceSaving(true);
    try {
      const {data} = await api.put('/profile/theme-mode',{mode:next},{carevmaPageLoader:false,carevmaConfirm:false});
      const saved = data?.mode === 'dark' ? 'dark' : 'light';
      cachePersonalMode(user.id,saved);
      setPersonalThemeMode(saved);
      setPersonalAppearance(saved);
    } catch {
      setPersonalThemeMode(previous);
      setPersonalAppearance(previous);
      emitToast('Could not save display mode. Please retry.', 'error');
    } finally { setAppearanceSaving(false); }
  };
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [unread, setUnread] = useState(0);
  const [liveCount, setLiveCount] = useState(0);
  const [realtimeNotice, setRealtimeNotice] = useState(null);
  const [notificationOpen, setNotificationOpen] = useState(false);
  const [notificationRows, setNotificationRows] = useState([]);
  const [notificationLoading, setNotificationLoading] = useState(false);
  const latestNotificationIdRef = useRef(0);
  const notificationBaselineReadyRef = useRef(false);
  const notificationUserRef = useRef(null);
  const notificationMenuRef = useRef(null);
  const profileMenuRef = useRef(null);
  const [profileMenuOpen, setProfileMenuOpen] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const employeeProfileIncomplete = profileIncomplete(user);
  const nav = useMemo(() => navFor(user), [user]);
  const pages = useMemo(() => nav.flatMap(item => item.children ? item.children.map(child => ({ label: child.label, to: child.to })) : (item.to ? [{ label: item.label, to: item.to }] : [])), [nav]);

  const notificationPath = user?.role === 'employee' ? '/employee/notifications' : user?.role === 'client' ? '/doctor/notifications' : user?.role === 'hr' ? '/hr/notifications' : '/admin/notifications';
  const loadNotificationDropdown = async () => {
    if (!user) return;
    setNotificationLoading(true);
    try {
      const { data } = await api.get('/notifications?per_page=8');
      setNotificationRows((data?.data || []).slice(0, 8));
    } catch {
      setNotificationRows([]);
      emitToast('Notifications could not be loaded. Check your connection and try again.', 'error');
    } finally {
      setNotificationLoading(false);
    }
  };

  // Subscribe once per signed-in user. Initial history is a silent baseline;
  // only notifications arriving after it play a chime. Polling covers missed
  // realtime messages, while the notification ID prevents replay.
  useEffect(() => {
    if (!user || employeeProfileIncomplete) {
      setUnread(0);
      setNotificationRows([]);
      return undefined;
    }
    let alive = true;
    if (notificationUserRef.current !== user.id) {
      notificationUserRef.current = user.id;
      latestNotificationIdRef.current = 0;
      notificationBaselineReadyRef.current = false;
    }
    const uninstallUnlock = installNotificationSoundUnlock();
    const loadUnread = async () => {
      try { const {data} = await api.get('/notifications/unread-count', { carevmaPageLoader: false }); if (alive) setUnread(data.count || 0); } catch {}
    };
    const checkNewNotifications = async () => {
      try {
        const { data } = await api.get('/notifications?per_page=15', { carevmaPageLoader: false });
        if (!alive) return;
        const rows = Array.isArray(data?.data) ? data.data : [];
        const previous = latestNotificationIdRef.current;
        const newest = rows.reduce((max, row) => Math.max(max, Number(row?.id) || 0), previous);
        // No historical sounds on login, refresh or opening the page.
        if (notificationBaselineReadyRef.current) {
          const incoming = rows.filter(row => Number(row?.id) > previous && !row?.is_read);
          if (incoming.length) playNotificationSoundOnce(user.id, incoming[incoming.length - 1].id);
        }
        latestNotificationIdRef.current = newest;
        notificationBaselineReadyRef.current = true;
      } catch {
        // If the first load fails, do not treat a later history fetch as new.
      }
    };
    loadUnread();
    checkNewNotifications();
    const intervalId = setInterval(() => { loadUnread(); checkNewNotifications(); }, 60000);
    const onFocus = () => { loadUnread(); checkNewNotifications(); };
    const onRead = () => setUnread(0);
    window.addEventListener('focus', onFocus);
    window.addEventListener('carevma:notifications-read', onRead);

    const unsubscribePusher = subscribeToCareVmaNotifications(user.id, payload => {
      if (!alive) return;
      const notificationId = Number(payload?.id) || 0;
      if (notificationId) {
        // Mark this ID as seen before the next HTTP poll.
        latestNotificationIdRef.current = Math.max(latestNotificationIdRef.current, notificationId);
        if (notificationBaselineReadyRef.current) playNotificationSoundOnce(user.id, notificationId);
      }
      setUnread(Number.isFinite(Number(payload?.unread_count)) ? Number(payload.unread_count) : current => current + 1);
      setRealtimeNotice(payload || null);
      if (payload?.id) {
        setNotificationRows(current => [payload, ...current.filter(item => item.id !== payload.id)].slice(0, 8));
      }
      window.dispatchEvent(new CustomEvent('carevma:notification', { detail: payload || {} }));
    });

    return () => {
      alive = false;
      clearInterval(intervalId);
      window.removeEventListener('focus', onFocus);
      window.removeEventListener('carevma:notifications-read', onRead);
      unsubscribePusher?.();
      uninstallUnlock();
    };
  }, [user?.id, employeeProfileIncomplete]);

  useEffect(() => {
    if (!user || !['admin', 'superadmin', 'hr'].includes(user.role) || !can(user, 'live-users')) {
      setLiveCount(0);
      return undefined;
    }

    let alive = true;
    const loadLiveCount = async () => {
      try {
        // Sidebar only needs the live total. Avoid loading/reconciling the full
        // Live Workforce payload every 15 seconds on every Admin/HR page.
        const { data } = await api.get('/management/live-count', { carevmaPageLoader: false });
        if (alive) setLiveCount(Math.max(0, Number(data?.total) || 0));
      } catch {}
    };

    loadLiveCount();
    const fallbackPoll = setInterval(loadLiveCount, 15000);
    const realtimeRefresh = () => loadLiveCount();
    const focusRefresh = () => loadLiveCount();
    window.addEventListener('carevma:notification', realtimeRefresh);
    window.addEventListener('focus', focusRefresh);

    return () => {
      alive = false;
      clearInterval(fallbackPoll);
      window.removeEventListener('carevma:notification', realtimeRefresh);
      window.removeEventListener('focus', focusRefresh);
    };
  }, [user?.id, user?.role, user?.permissions]);

  useEffect(() => {
    if (!realtimeNotice) return;
    const id = setTimeout(() => setRealtimeNotice(null), 6500);
    return () => clearTimeout(id);
  }, [realtimeNotice?.id]);

  useEffect(() => {
    if (!notificationOpen) return;
    loadNotificationDropdown();
    const onPointerDown = event => {
      if (notificationMenuRef.current && !notificationMenuRef.current.contains(event.target)) setNotificationOpen(false);
    };
    const onKeyDown = event => { if (event.key === 'Escape') setNotificationOpen(false); };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [notificationOpen, user?.id]);

  const openNotification = async row => {
    if (!row) return;
    if (!row.is_read && row.user_id) {
      try {
        await api.post(`/notifications/${row.id}/read`);
        setNotificationRows(current => current.map(item => item.id === row.id ? { ...item, is_read: true } : item));
        setUnread(current => Math.max(0, current - 1));
      } catch {
        emitToast('This notification could not be marked as read. Please try again.', 'error');
      }
    }
    setNotificationOpen(false);
    if (row.action_url) navigate(row.action_url);
  };

  const markAllNotificationsRead = async () => {
    try {
      await api.post('/notifications/read-all');
      setNotificationRows(current => current.map(item => ({ ...item, is_read: true })));
      setUnread(0);
      window.dispatchEvent(new Event('carevma:notifications-read'));
    } catch {
      emitToast('Notifications could not be marked as read. Please try again.', 'error');
    }
  };

  const formatNotificationTime = value => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const diff = Date.now() - date.getTime();
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return formatDate(value, user?.assigned_timezone || user?.timezone || 'UTC', { month: 'short', day: 'numeric' });
  };

  useEffect(() => {
    if (!employeeProfileIncomplete) return;
    if (location.pathname !== '/employee/profile') {
      navigate('/employee/profile', { replace: true });
    }
  }, [employeeProfileIncomplete, location.pathname, navigate]);

  useEffect(() => {
    if (!profileMenuOpen) return;
    const close = event => {
      if (profileMenuRef.current && !profileMenuRef.current.contains(event.target)) setProfileMenuOpen(false);
    };
    const escape = event => { if (event.key === 'Escape') setProfileMenuOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [profileMenuOpen]);

  const profilePath = user?.role === 'employee' ? '/employee/profile'
    : user?.role === 'client' ? '/doctor/profile'
    : user?.role === 'hr' ? '/hr/account'
    : '/admin/account';
  const settingsPath = user?.role === 'employee' ? '/employee/settings' : profilePath;
  const openProfilePath = path => { setProfileMenuOpen(false); navigate(path); };

  const signOut = async () => { await logout(); navigate('/login', { replace: true }); };
  const roleLabel = user?.role === 'client' ? 'Client / Doctor' : user?.role === 'employee' ? 'Team Member / VA' : user?.role === 'hr' ? 'Human Resources' : 'Administrator';
  const [footerBrand, setFooterBrand] = useState(() => window.__carevmaBrand || {});
  useEffect(() => {
    const refreshFooter = () => setFooterBrand({ ...(window.__carevmaBrand || {}) });
    window.addEventListener('carevma:brand-changed', refreshFooter);
    refreshFooter();
    return () => window.removeEventListener('carevma:brand-changed', refreshFooter);
  }, []);
  const footerCopyright = typeof footerBrand.footer_text === 'string' && footerBrand.footer_text.trim()
    ? footerBrand.footer_text.trim()
    : `© ${new Date().getFullYear()} ${footerBrand.company_name || 'CareVMA'}. All rights reserved.`;


  return <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''}`}>
    {mobileOpen && <button className="mobile-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)}/>} 
    <aside className={`sidebar ${mobileOpen ? 'mobile-open' : ''}`}>
      <div className="brand-row">
        <BrandLogo compact={collapsed}/>
        <button className="icon-btn mobile-only" onClick={() => setMobileOpen(false)} aria-label="Close navigation"><X size={20}/></button>
      </div>
      <div className="workspace-chip">{!collapsed && <><span className="workspace-dot"/><div><strong>Operations</strong><small>{roleLabel}</small></div></>}</div>
      <nav className="sidebar-nav" aria-label="Primary">{nav.map((item, index) => <Fragment key={item.label}>
        {item.group && item.group !== nav[index - 1]?.group && (collapsed ? index > 0 && <div className="nav-group-separator" role="presentation"/> : <div className="nav-group-label eyebrow">{item.group}</div>)}
        <NavItem item={item} collapsed={collapsed} mobileClose={() => setMobileOpen(false)} liveCount={liveCount}/>
      </Fragment>)}</nav>
      <div className="sidebar-foot">
        <button className="nav-link danger-link" onClick={signOut} title="Sign out"><span className="nav-icon"><LogOut size={19}/></span>{!collapsed && <span>Sign out</span>}</button>
      </div>
    </aside>
    <div className="app-main">
      {realtimeNotice && <div className="realtime-toast" role="status" aria-live="polite">
        <div className="realtime-toast-icon"><NotificationIcon row={realtimeNotice} size={18}/></div>
        <button type="button" className="realtime-toast-body" onClick={() => { if (realtimeNotice.action_url) navigate(realtimeNotice.action_url); setRealtimeNotice(null); }}>
          <strong>{realtimeNotice.title || 'CareVMA notification'}</strong>
          <span>{realtimeNotice.message || 'A new update is available.'}</span>
        </button>
        <button type="button" className="realtime-toast-close" onClick={() => setRealtimeNotice(null)} aria-label="Dismiss notification"><X size={16}/></button>
      </div>}
      <header className="topbar">
        <div className="topbar-left">
          <button className="icon-btn mobile-only" onClick={() => setMobileOpen(true)} aria-label="Open navigation"><Menu size={21}/></button>
          <button className="icon-btn desktop-only" onClick={() => setCollapsed(v => !v)} aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}>{collapsed ? <PanelLeftOpen size={20}/> : <PanelLeftClose size={20}/>}</button>
          <div className="topbar-context"><span>CareVMA</span><strong>{roleLabel}</strong></div>
        </div>
        {!employeeProfileIncomplete && <TopbarSearch pages={pages}/>}
        <div className="topbar-right">
          <button type="button" className="icon-btn personal-theme-toggle"
            onClick={togglePersonalAppearance} disabled={appearanceSaving}
            aria-label={personalAppearance === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
            title={personalAppearance === 'dark' ? 'Light mode (only for you)' : 'Dark mode (only for you)'}
            aria-pressed={personalAppearance === 'dark'}>
            {personalAppearance === 'dark' ? <Sun size={19}/> : <Moon size={19}/>}
          </button>
          <LiveNavbarClock user={user}/>
          {!employeeProfileIncomplete && <div className="notification-menu-wrap" ref={notificationMenuRef}>
            <button
              className={`icon-btn notification-bell ${notificationOpen ? 'active' : ''}`}
              onClick={() => setNotificationOpen(open => !open)}
              aria-expanded={notificationOpen}
              aria-haspopup="dialog"
              aria-label={`Notifications${unread?` (${unread} unread)`:``}`}
            >
              <Bell size={19} strokeWidth={2} aria-hidden="true"/>{unread>0&&<span className="notification-count">{unread>99?'99+':unread}</span>}
            </button>
            {notificationOpen && <div className="notification-dropdown" role="dialog" aria-label="Recent notifications">
              <div className="notification-dropdown-head">
                <div><strong>Notifications</strong><span>{unread ? `${unread} unread` : 'You are all caught up'}</span></div>
                {unread > 0 && <button type="button" onClick={markAllNotificationsRead}>Mark all read</button>}
              </div>
              <div className="notification-dropdown-body">
                {notificationLoading ? <div className="notification-dropdown-state">Loading notifications…</div> : notificationRows.length ? notificationRows.slice(0,8).map(row => <button type="button" key={row.id} className={`notification-dropdown-item ${row.is_read ? '' : 'unread'}`} onClick={() => openNotification(row)}>
                  <span className="notification-dropdown-icon"><NotificationIcon row={row} size={15}/></span>
                  <span className="notification-dropdown-copy"><strong>{row.title || row.type || 'Notification'}</strong><span>{row.message || 'A CareVMA update is available.'}</span><small>{formatNotificationTime(row.created_at)}</small></span>
                  {!row.is_read && <i aria-label="Unread"/>}
                </button>) : <div className="notification-dropdown-state"><Bell size={21}/><strong>No notifications</strong><span>New activity will appear here.</span></div>}
              </div>
              <button type="button" className="notification-dropdown-footer" onClick={() => { setNotificationOpen(false); navigate(notificationPath); }}>View all notifications <ChevronRight size={15}/></button>
            </div>}
          </div>}
          <div className="profile-menu-wrap" ref={profileMenuRef}>
            <button type="button" className={`profile-menu-trigger ${profileMenuOpen ? 'active' : ''}`} onClick={() => setProfileMenuOpen(open => !open)} aria-expanded={profileMenuOpen} aria-haspopup="menu">
              <span className="avatar small"><AvatarMedia src={user?.role==='employee' ? (user?.employee?.profile_picture || user?.profile_picture) : user?.role==='client' ? (user?.client?.profile_picture || user?.profile_picture) : user?.profile_picture} alt={user?.name||'User'} fallback={user?.name?.[0]?.toUpperCase()}/></span>
              <span className="profile-menu-trigger-copy"><strong>{user?.name}</strong><small>{roleLabel}</small></span>
              <ChevronDown size={15}/>
            </button>
            {profileMenuOpen && <div className="profile-dropdown" role="menu">
              <div className="profile-dropdown-head"><strong>{user?.name}</strong><span>{user?.email}</span></div>
              <button type="button" onClick={() => openProfilePath(profilePath)}><UserCircle2 size={16}/><span>Profile</span></button>
              <button type="button" onClick={() => openProfilePath(settingsPath)}><Settings size={16}/><span>Settings</span></button>
              <div className="profile-dropdown-divider"/>
              <button type="button" className="profile-dropdown-logout" onClick={signOut}><LogOut size={16}/><span>Logout</span></button>
            </div>}
          </div>
        </div>
      </header>
      <main className="content">
        <BirthdayPopup user={user}/>
        {employeeProfileIncomplete && <div className="profile-lock-banner" role="status">
          <FileCheck2 size={20}/>
          <div><strong>Complete your profile to unlock CareVMA</strong><span>Finish the required personal fields below. Dashboard, schedules, attendance, notifications and settings will unlock automatically after you save a complete profile.</span></div>
        </div>}
        <RouteLoadingOutlet/>
      </main>
      <footer className="carevma-dashboard-footer" role="contentinfo" aria-label="Portal copyright">
        <span>{footerCopyright}</span>
      </footer>
    </div>
  </div>;
}
