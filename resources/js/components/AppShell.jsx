import { useEffect, useMemo, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard, Users, UserRoundCog, CalendarDays, Clock3, FileCheck2, TimerReset,
  ReceiptText, SlidersHorizontal, Bell, UserCircle2, Settings, LogOut, Menu, X, BriefcaseBusiness,
  Stethoscope, ClipboardList, ChevronDown, ChevronRight, PanelLeftClose, PanelLeftOpen, Activity, CircleDollarSign
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import api from '../api';
import { subscribeToCareVmaNotifications } from '../pusher';
import BrandLogo from './BrandLogo';
import LiveNavbarClock from './LiveNavbarClock';
import BirthdayPopup from './BirthdayPopup';
import { AvatarMedia } from './ui';
import { formatDate } from '../timezone';
import { emitToast } from '../feedback';
import '../../css/live-workforce-badge.css';
import '../../css/carevma-brand-clock.css';
import '../../css/birthday-popup.css';
import '../../css/profile-onboarding-lock.css';
import '../../css/admin-avatar-media.css';
import '../../css/page-transition-loader.css';

const managementBase = role => role === 'hr' ? '/hr' : '/admin';


function profileIncomplete(user) {
  if (user?.role !== 'employee') return false;
  const value = user?.employee?.profile_completed;
  return value === false || value === 0 || value === '0';
}

function navFor(user) {
  if (!user) return [];
  if (['admin', 'superadmin'].includes(user.role)) {
    return [
      { label: 'Dashboard', to: '/admin/dashboard', icon: LayoutDashboard },
      { label: 'Live Workforce', to: '/admin/live-users', icon: Activity },
      { label: 'Employees', to: '/admin/employees', icon: Users },
      { label: 'HR Team', to: '/admin/hr-team', icon: UserRoundCog },
      { label: 'Clients', to: '/admin/clients', icon: Stethoscope },
      { label: 'Schedules', to: '/admin/schedules', icon: CalendarDays },
      { label: 'Attendance', to: '/admin/attendance', icon: Clock3 },
      { label: 'Change Requests', to: '/admin/change-requests', icon: FileCheck2 },
      { label: 'Overtime Review', to: '/admin/overtime', icon: TimerReset },
      { label: 'Invoices', to: '/admin/invoices', icon: ReceiptText },
      { label: 'Company Finance', to: '/admin/company-finance', icon: CircleDollarSign },
      {
        label: 'Configuration', icon: SlidersHorizontal, children: [
          ['Departments','departments'], ['Roles','roles'], ['Employment Types','employment-types'],
          ['Shift Types','shift-types'], ['Designations','designations'], ['Employee Statuses','employee-statuses'],
          ['Expertise','expertises'], ['Reporting Managers','reporting-managers'], ['Services','services'], ['Contract Types','contracts'],
        ].map(([label, slug]) => ({ label, to: `/admin/options/${slug}` })),
      },
      { label: 'Notifications', to: '/admin/notifications', icon: Bell },
      { label: 'My Account', to: '/admin/account', icon: UserCircle2 },
    ];
  }
  if (user.role === 'hr') {
    return [
      { label: 'Dashboard', to: '/hr/dashboard', icon: LayoutDashboard },
      { label: 'Live Workforce', to: '/hr/live-users', icon: Activity },
      { label: 'Employees', to: '/hr/employees', icon: Users },
      { label: 'Schedules', to: '/hr/schedules', icon: CalendarDays },
      { label: 'Attendance', to: '/hr/attendance', icon: Clock3 },
      { label: 'Change Requests', to: '/hr/change-requests', icon: FileCheck2 },
      { label: 'Overtime Review', to: '/hr/overtime', icon: TimerReset },
      {
        label: 'HR Configuration', icon: SlidersHorizontal, children: [
          ['Departments','departments'], ['Roles','roles'], ['Employment Types','employment-types'],
          ['Shift Types','shift-types'], ['Designations','designations'], ['Employee Statuses','employee-statuses'],
          ['Expertise','expertises'], ['Reporting Managers','reporting-managers'],
        ].map(([label, slug]) => ({ label, to: `/hr/options/${slug}` })),
      },
      { label: 'Notifications', to: '/hr/notifications', icon: Bell },
      { label: 'My Account', to: '/hr/account', icon: UserCircle2 },
    ];
  }
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
      { label: 'Notifications', to: '/employee/notifications', icon: Bell },
      { label: 'Settings', to: '/employee/settings', icon: Settings },
    ];
  }
  if (user.role === 'client') {
    return [
      { label: 'Assigned Team', to: '/doctor/dashboard', icon: Users },
      { label: 'Notifications', to: '/doctor/notifications', icon: Bell },
      { label: 'My Profile', to: '/doctor/profile', icon: UserCircle2 },
    ];
  }
  return [];
}

function NavItem({ item, collapsed, mobileClose, liveCount = 0, beginPageLoad }) {
  const location = useLocation();
  const [open, setOpen] = useState(() => item.children?.some(c => location.pathname.startsWith(c.to)) ?? false);
  const Icon = item.icon;
  if (item.children) {
    return <div className="nav-group">
      <button className={`nav-link nav-parent ${open ? 'is-open' : ''}`} onClick={() => setOpen(v => !v)} title={collapsed ? item.label : undefined}>
        <span className="nav-icon">{Icon && <Icon size={19}/>}</span>{!collapsed && <><span>{item.label}</span>{open ? <ChevronDown size={15}/> : <ChevronRight size={15}/>}</>}
      </button>
      {open && !collapsed && <div className="nav-children">{item.children.map(child => <NavLink key={child.to} to={child.to} onClick={() => { beginPageLoad?.(); mobileClose?.(); }} className={({isActive}) => `nav-child ${isActive ? 'active' : ''}`}>{child.label}</NavLink>)}</div>}
    </div>;
  }
  const isLiveWorkforce = item.label === 'Live Workforce';
  return <NavLink to={item.to} onClick={() => { beginPageLoad?.(); mobileClose?.(); }} title={collapsed ? item.label : undefined} className={({isActive}) => `nav-link ${isActive ? 'active' : ''}`}>
    <span className="nav-icon nav-icon-with-badge">{Icon && <Icon size={19}/>} {isLiveWorkforce && liveCount > 0 && collapsed && <span className="live-count-badge live-count-badge-mini">{liveCount > 99 ? '99+' : liveCount}</span>}</span>
    {!collapsed && <span>{item.label}</span>}
    {isLiveWorkforce && liveCount > 0 && !collapsed && <span className="live-count-badge"><i/> {liveCount > 99 ? '99+' : liveCount}</span>}
  </NavLink>;
}

export default function AppShell() {
  const { user, logout } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [unread, setUnread] = useState(0);
  const [liveCount, setLiveCount] = useState(0);
  const [realtimeNotice, setRealtimeNotice] = useState(null);
  const [notificationOpen, setNotificationOpen] = useState(false);
  const [notificationRows, setNotificationRows] = useState([]);
  const [notificationLoading, setNotificationLoading] = useState(false);
  const [pageLoading, setPageLoading] = useState(false);
  const pageLoadSeenRequestRef = useRef(false);
  const pageLoadFallbackRef = useRef(null);
  const pageLoadFinishRef = useRef(null);
  const notificationMenuRef = useRef(null);
  const navigate = useNavigate();
  const location = useLocation();
  const employeeProfileIncomplete = profileIncomplete(user);
  const nav = useMemo(() => navFor(user), [user]);

  const finishPageLoad = () => {
    if (pageLoadFinishRef.current) clearTimeout(pageLoadFinishRef.current);
    pageLoadFinishRef.current = setTimeout(() => {
      setPageLoading(false);
      pageLoadSeenRequestRef.current = false;
      if (typeof window !== 'undefined') window.__carevmaPageNavigationActive = false;
    }, 140);
  };

  const beginPageLoad = () => {
    if (pageLoadFallbackRef.current) clearTimeout(pageLoadFallbackRef.current);
    if (pageLoadFinishRef.current) clearTimeout(pageLoadFinishRef.current);
    pageLoadSeenRequestRef.current = false;
    if (typeof window !== 'undefined') window.__carevmaPageNavigationActive = true;
    setPageLoading(true);
    pageLoadFallbackRef.current = setTimeout(() => finishPageLoad(), 8000);
  };

  useEffect(() => {
    const onInternalLinkClick = event => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const anchor = event.target?.closest?.('a[href]');
      if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) return;
      try {
        const url = new URL(anchor.href, window.location.origin);
        const isAppRoute = ['/admin/', '/hr/', '/employee/', '/doctor/'].some(prefix => url.pathname.startsWith(prefix));
        if (url.origin === window.location.origin && isAppRoute && url.pathname !== window.location.pathname) beginPageLoad();
      } catch {}
    };
    const onRequestState = event => {
      if (!window.__carevmaPageNavigationActive) return;
      const pending = Math.max(0, Number(event?.detail?.pending) || 0);
      if (pending > 0) {
        pageLoadSeenRequestRef.current = true;
        setPageLoading(true);
        return;
      }
      if (pageLoadSeenRequestRef.current) finishPageLoad();
    };
    const onPopState = () => beginPageLoad();
    document.addEventListener('click', onInternalLinkClick, true);
    window.addEventListener('carevma:page-request-state', onRequestState);
    window.addEventListener('popstate', onPopState);
    return () => {
      document.removeEventListener('click', onInternalLinkClick, true);
      window.removeEventListener('carevma:page-request-state', onRequestState);
      window.removeEventListener('popstate', onPopState);
      if (pageLoadFallbackRef.current) clearTimeout(pageLoadFallbackRef.current);
      if (pageLoadFinishRef.current) clearTimeout(pageLoadFinishRef.current);
    };
  }, []);

  useEffect(() => {
    if (!pageLoading || pageLoadSeenRequestRef.current) return;
    const noRequestFallback = setTimeout(() => finishPageLoad(), 650);
    return () => clearTimeout(noRequestFallback);
  }, [location.pathname]);

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

  useEffect(() => {
    if (!user || employeeProfileIncomplete) {
      setUnread(0);
      setNotificationRows([]);
      return undefined;
    }
    let alive = true;
    const loadUnread = async () => {
      try { const {data} = await api.get('/notifications/unread-count'); if (alive) setUnread(data.count || 0); } catch {}
    };

    loadUnread();
    const intervalId = setInterval(loadUnread, 60000);
    const onFocus = () => loadUnread();
    const onRead = () => setUnread(0);
    window.addEventListener('focus', onFocus);
    window.addEventListener('carevma:notifications-read', onRead);

    const unsubscribePusher = subscribeToCareVmaNotifications(user.id, payload => {
      if (!alive) return;
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
    };
  }, [user?.id, employeeProfileIncomplete]);

  useEffect(() => {
    if (!user || !['admin', 'superadmin', 'hr'].includes(user.role)) {
      setLiveCount(0);
      return undefined;
    }

    let alive = true;
    const loadLiveCount = async () => {
      try {
        const { data } = await api.get('/management/live-users');
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
  }, [user?.id, user?.role]);

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
    if (row.action_url) { beginPageLoad(); navigate(row.action_url); }
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

  const signOut = async () => { await logout(); navigate('/login', { replace: true }); };
  const roleLabel = user?.role === 'client' ? 'Client / Doctor' : user?.role === 'employee' ? 'Team Member / VA' : user?.role === 'hr' ? 'Human Resources' : 'Administrator';

  return <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''} ${pageLoading ? 'is-page-loading' : ''}`}>
    {pageLoading && <div className="carevma-page-transition-line" role="status" aria-live="polite" aria-label="Loading page"><span/></div>}
    {mobileOpen && <button className="mobile-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)}/>} 
    <aside className={`sidebar ${mobileOpen ? 'mobile-open' : ''}`}>
      <div className="brand-row">
        <BrandLogo compact={collapsed}/>
        <button className="icon-btn mobile-only" onClick={() => setMobileOpen(false)}><X size={20}/></button>
      </div>
      <div className="workspace-chip">{!collapsed && <><span className="workspace-dot"/><div><strong>Operations</strong><small>{roleLabel}</small></div></>}</div>
      <nav className="sidebar-nav">{nav.map(item => <NavItem key={item.label} item={item} collapsed={collapsed} mobileClose={() => setMobileOpen(false)} liveCount={liveCount} beginPageLoad={beginPageLoad}/>)}</nav>
      <div className="sidebar-foot">
        {!collapsed && <div className="user-mini"><div className="avatar"><AvatarMedia src={user?.employee?.profile_picture || user?.client?.profile_picture} alt={user?.name||'User'} fallback={user?.name?.split(' ').map(x => x[0]).slice(0,2).join('').toUpperCase()}/></div><div><strong>{user?.name}</strong><span>{user?.email}</span></div></div>}
        <button className="nav-link danger-link" onClick={signOut} title="Sign out"><span className="nav-icon"><LogOut size={19}/></span>{!collapsed && <span>Sign out</span>}</button>
      </div>
    </aside>
    <div className="app-main">
      {realtimeNotice && <div className="realtime-toast" role="status" aria-live="polite">
        <div className="realtime-toast-icon"><Bell size={18}/></div>
        <button type="button" className="realtime-toast-body" onClick={() => { if (realtimeNotice.action_url) { beginPageLoad(); navigate(realtimeNotice.action_url); } setRealtimeNotice(null); }}>
          <strong>{realtimeNotice.title || 'CareVMA notification'}</strong>
          <span>{realtimeNotice.message || 'A new update is available.'}</span>
        </button>
        <button type="button" className="realtime-toast-close" onClick={() => setRealtimeNotice(null)} aria-label="Dismiss notification"><X size={16}/></button>
      </div>}
      <header className="topbar">
        <div className="topbar-left">
          <button className="icon-btn mobile-only" onClick={() => setMobileOpen(true)}><Menu size={21}/></button>
          <button className="icon-btn desktop-only" onClick={() => setCollapsed(v => !v)}>{collapsed ? <PanelLeftOpen size={20}/> : <PanelLeftClose size={20}/>}</button>
          <div className="topbar-context"><span>CareVMA</span><strong>{roleLabel}</strong></div>
        </div>
        <div className="topbar-right">
          <LiveNavbarClock user={user}/>
          {!employeeProfileIncomplete && <div className="notification-menu-wrap" ref={notificationMenuRef}>
            <button
              className={`icon-btn notification-bell ${notificationOpen ? 'active' : ''}`}
              onClick={() => setNotificationOpen(open => !open)}
              aria-expanded={notificationOpen}
              aria-haspopup="dialog"
              aria-label={`Notifications${unread?` (${unread} unread)`:``}`}
            >
              <Bell size={18}/>{unread>0&&<span className="notification-count">{unread>99?'99+':unread}</span>}
            </button>
            {notificationOpen && <div className="notification-dropdown" role="dialog" aria-label="Recent notifications">
              <div className="notification-dropdown-head">
                <div><strong>Notifications</strong><span>{unread ? `${unread} unread` : 'You are all caught up'}</span></div>
                {unread > 0 && <button type="button" onClick={markAllNotificationsRead}>Mark all read</button>}
              </div>
              <div className="notification-dropdown-body">
                {notificationLoading ? <div className="notification-dropdown-state">Loading notifications…</div> : notificationRows.length ? notificationRows.slice(0,8).map(row => <button type="button" key={row.id} className={`notification-dropdown-item ${row.is_read ? '' : 'unread'}`} onClick={() => openNotification(row)}>
                  <span className="notification-dropdown-icon"><Bell size={15}/></span>
                  <span className="notification-dropdown-copy"><strong>{row.title || row.type || 'Notification'}</strong><span>{row.message || 'A CareVMA update is available.'}</span><small>{formatNotificationTime(row.created_at)}</small></span>
                  {!row.is_read && <i aria-label="Unread"/>}
                </button>) : <div className="notification-dropdown-state"><Bell size={21}/><strong>No notifications</strong><span>New activity will appear here.</span></div>}
              </div>
              <button type="button" className="notification-dropdown-footer" onClick={() => { setNotificationOpen(false); beginPageLoad(); navigate(notificationPath); }}>View all notifications <ChevronRight size={15}/></button>
            </div>}
          </div>}
          <div className="status-pill"><span/>Secure session</div><div className="avatar small"><AvatarMedia src={user?.employee?.profile_picture || user?.client?.profile_picture} alt={user?.name||'User'} fallback={user?.name?.[0]?.toUpperCase()}/></div>
        </div>
      </header>
      <main className="content">
        <BirthdayPopup user={user}/>
        {employeeProfileIncomplete && <div className="profile-lock-banner" role="status">
          <FileCheck2 size={20}/>
          <div><strong>Complete your profile to unlock CareVMA</strong><span>Finish the required personal fields below. Dashboard, schedules, attendance, notifications and settings will unlock automatically after you save a complete profile.</span></div>
        </div>}
        <div className={`carevma-route-content ${pageLoading ? 'is-loading' : ''}`} aria-busy={pageLoading ? 'true' : 'false'}>
          <Outlet/>
        </div>
      </main>
    </div>
  </div>;
}
