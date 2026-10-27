import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  CalendarDays,
  Clock3,
  ClipboardList,
  MoreVertical,
  Pencil,
  Search,
  Activity,
  Globe2,
  Plus,
  Save,
  Trash2,
  UserRound,
  UsersRound,
} from 'lucide-react';
import api, { messageFrom } from '../api';
import {
  Badge,
  Button,
  Card,
  Input,
  Loading,
  Notice,
  Pagination,
  Select,
  Table,
  money,
  mins,
  dateTime,
} from '../components/ui';
import TimezonePicker from '../components/TimezonePicker';
import { formatDate, formatTime24, formatWeekday, weekdayFromDateOnly } from '../timezone';
import '../../css/client-profile.css';
import '../../css/dual-timezone-tables.css';


function tzDate(value,timeZone){return formatDate(value,timeZone||'UTC')}

function DualTime({value,doctorTz,vaTz,live=false}){
  if(!value) return live?<Badge tone="warning">Live</Badge>:<span>—</span>;
  return <div className="dual-time-cell">
    <div className="dual-time-row doctor-time"><strong>Doctor</strong><span>{dateTime(value,doctorTz||'UTC')}</span><small>{doctorTz||'UTC'}</small></div>
    <div className="dual-time-row va-time"><strong>VA</strong><span>{dateTime(value,vaTz||'UTC')}</span><small>{vaTz||'UTC'}</small></div>
  </div>;
}
function DualDate({value,doctorTz,vaTz}){
  return <div className="dual-date-cell"><span><strong>Doctor</strong> {tzDate(value,doctorTz||'UTC')}</span><span><strong>VA</strong> {tzDate(value,vaTz||'UTC')}</span></div>;
}

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const TABS = [
  { key: 'profile', label: 'Profile', icon: UserRound, suffix: 'profile' },
  { key: 'timezone', label: 'Timezone', icon: Clock3, suffix: 'profile-timezone' },
  { key: 'schedule', label: 'Schedule', icon: CalendarDays, suffix: 'schedule' },
  { key: 'assign', label: 'Assign VA', icon: UsersRound, suffix: 'assign-employee' },
  { key: 'report', label: 'Daily Work Report', icon: ClipboardList, suffix: 'daily-work-report' },
];

const emptySchedule = {
  employee_id: '',
  date: '',
  weekday: 'Monday',
  start_time: '09:00',
  end_time: '17:00',
  per_hour_rate: '',
  break_allowed_hours: '0.30',
  enabled: true,
  repeat: true,
};

function tabFromPath(pathname) {
  if (pathname.endsWith('/profile-timezone')) return 'timezone';
  if (pathname.endsWith('/schedule')) return 'schedule';
  if (pathname.endsWith('/assign-employee')) return 'assign';
  if (pathname.endsWith('/daily-work-report')) return 'report';
  return 'profile';
}


function weekdayFromDate(value) { return weekdayFromDateOnly(value) || null; }

function timeInZone(value,timeZone){return formatTime24(value,timeZone||'UTC')}

function weekdayInZone(value,timeZone){return formatWeekday(value,timeZone||'UTC')}

function formatTime12(value) {
  if (!value) return '—';
  const [rawHour = '0', rawMinute = '0'] = String(value).slice(0, 5).split(':');
  const hour = Math.max(0, Math.min(23, Number(rawHour) || 0));
  const minute = String(Math.max(0, Math.min(59, Number(rawMinute) || 0))).padStart(2, '0');
  const period = hour >= 12 ? 'PM' : 'AM';
  return `${hour % 12 || 12}:${minute} ${period}`;
}

function durationHM(value) {
  const decimal = Number(value || 0);
  if (!Number.isFinite(decimal) || decimal < 0) return '0.00';
  const totalMinutes = Math.round(decimal * 60);
  const hours = Math.floor(totalMinutes / 60);
  const minutes = totalMinutes % 60;
  return `${hours}.${String(minutes).padStart(2, '0')}`;
}

function hoursText(value) {
  return `${durationHM(value)} H.MM`;
}

function scheduleHours(startTime, endTime) {
  const toMinutes = value => {
    const [hours = '0', minutes = '0'] = String(value || '00:00').split(':');
    return (Number(hours) || 0) * 60 + (Number(minutes) || 0);
  };
  const start = toMinutes(startTime);
  let end = toMinutes(endTime);
  if (end <= start) end += 1440;
  return Math.max(0, (end - start) / 60);
}

function makeWeeklyDays() {
  return WEEKDAYS.reduce((days, weekday) => {
    days[weekday] = { selected: false, start_time: '09:00', end_time: '17:00', repeat: true };
    return days;
  }, {});
}

function clientPayload(form) {
  return {
    name: form.name || '',
    email: form.email || '',
    business_name: form.business_name || '',
    phone_number: form.phone_number || '',
    country: form.country || '',
    timezone: form.timezone || 'America/New_York',
    per_hour_charges: form.per_hour_charges === '' ? null : form.per_hour_charges,
    contract_type_id: form.contract_type_id || null,
    service_id: form.service_id || null,
    ring_center: !!form.ring_center,
  };
}

export default function ClientProfilePage() {
  const { clientId } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const activeTab = tabFromPath(location.pathname);

  const [client, setClient] = useState(null);
  const [form, setForm] = useState({});
  const [options, setOptions] = useState({ employees: [], services: [], contracts: [] });
  const [schedules, setSchedules] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const [scheduleOpen, setScheduleOpen] = useState(false);
  const [scheduleSearch, setScheduleSearch] = useState('');
  const [scheduleStatus, setScheduleStatus] = useState('all');
  const [editingSchedule, setEditingSchedule] = useState(null);
  const [scheduleForm, setScheduleForm] = useState(emptySchedule);
  const [weeklyAssign, setWeeklyAssign] = useState({
    employee_id: '',
    per_hour_rate: '',
    break_allowed_hours: '0.30',
    days: makeWeeklyDays(),
  });

  const [reportRows, setReportRows] = useState([]);
  const [reportMeta, setReportMeta] = useState(null);
  const [reportPage, setReportPage] = useState(1);
  const [reportLoading, setReportLoading] = useState(false);
  const [reportFilters, setReportFilters] = useState({ employee_id: '', from: '', to: '', search: '' });

  const notify = (type, text) => { if (text) setNotice({ type, text }); };

  const goTab = (tabKey) => {
    const tab = TABS.find(item => item.key === tabKey) || TABS[0];
    if (tab.key === 'assign') {
      setEditingSchedule(null);
      setWeeklyAssign({
        employee_id: '',
        per_hour_rate: '',
        break_allowed_hours: '0.30',
        days: makeWeeklyDays(),
      });
    }
    navigate(`/admin/client/${clientId}/${tab.suffix}`);
  };

  const loadCore = async (silent = false) => {
    if (!silent) setLoading(true);
    try {
      const [clientResponse, optionsResponse, scheduleResponse] = await Promise.all([
        api.get(`/admin/clients/${clientId}`),
        api.get('/management/options'),
        api.get('/management/schedules', { params: { client_id: clientId } }),
      ]);

      setClient(clientResponse.data);
      setForm(clientResponse.data);
      setOptions(optionsResponse.data || {});
      setSchedules(Array.isArray(scheduleResponse.data) ? scheduleResponse.data : []);
    } catch (error) {
      notify('error', messageFrom(error));
    } finally {
      if (!silent) setLoading(false);
    }
  };

  const loadReport = async (filters = reportFilters, page = reportPage) => {
    setReportLoading(true);
    try {
      const response = await api.get('/management/attendance', {
        params: {
          ...filters,
          client_id: clientId,
          page,
          per_page: 25,
        },
      });
      setReportRows(response.data?.data || []);
      setReportMeta(response.data || null);
    } catch (error) {
      notify('error', messageFrom(error));
    } finally {
      setReportLoading(false);
    }
  };

  useEffect(() => {
    loadCore();
  }, [clientId]);

  useEffect(() => {
    if (activeTab === 'report') loadReport(reportFilters, reportPage);
  }, [activeTab, reportPage, clientId]);

  const groupedSchedules = useMemo(() => {
    const groups = new Map();
    schedules.forEach(schedule => {
      const employee = schedule.employee || {};
      const key = String(schedule.employee_id || employee.id || 'unknown');
      if (!groups.has(key)) {
        groups.set(key, {
          employee,
          rate: schedule.per_hour_rate ?? client?.per_hour_charges ?? null,
          schedules: [],
        });
      }
      const group = groups.get(key);
      if (schedule.per_hour_rate !== null && schedule.per_hour_rate !== undefined) group.rate = schedule.per_hour_rate;
      group.schedules.push(schedule);
    });
    return [...groups.values()];
  }, [schedules, client]);

  const filteredScheduleGroups = useMemo(() => {
    const term = scheduleSearch.trim().toLowerCase();
    return groupedSchedules.map(group => {
      const employeeName = `${group.employee?.first_name || ''} ${group.employee?.last_name || ''}`.trim().toLowerCase();
      const employeeEmail = String(group.employee?.email || '').toLowerCase();
      const employeeCode = String(group.employee?.employee_code || '').toLowerCase();
      const matching = group.schedules.filter(schedule => {
        if (scheduleStatus === 'enabled' && !schedule.enabled) return false;
        if (scheduleStatus === 'disabled' && schedule.enabled) return false;
        if (!term) return true;
        const haystack = [
          employeeName,
          employeeEmail,
          employeeCode,
          schedule.weekday,
          schedule.employee_timezone,
          schedule.timezone,
          schedule.start_time,
          schedule.end_time,
        ].filter(Boolean).join(' ').toLowerCase();
        return haystack.includes(term);
      });
      return { ...group, schedules: matching };
    }).filter(group => group.schedules.length);
  }, [groupedSchedules, scheduleSearch, scheduleStatus]);

  const scheduleStats = useMemo(() => {
    const active = schedules.filter(schedule => !!schedule.enabled).length;
    const hours = schedules.reduce((sum, schedule) => sum + Number(schedule.no_of_hours || 0), 0);
    return {
      assigned: groupedSchedules.length,
      active,
      hours,
      timezone: client?.timezone || 'Not assigned',
    };
  }, [schedules, groupedSchedules, client]);

  const assignedEmployeeIds = useMemo(
    () => new Set(schedules.map(schedule => String(schedule.employee_id))),
    [schedules],
  );

  const assignedEmployees = useMemo(() => {
    const map = new Map();
    schedules.forEach(schedule => {
      if (schedule.employee?.id) map.set(String(schedule.employee.id), schedule.employee);
    });
    return [...map.values()];
  }, [schedules]);

  const selectedAssignEmployee = useMemo(
    () => (options.employees || []).find(employee => String(employee.id) === String(weeklyAssign.employee_id)) || null,
    [options.employees, weeklyAssign.employee_id],
  );

  const selectedAssignDays = useMemo(
    () => WEEKDAYS.filter(day => weeklyAssign.days?.[day]?.selected),
    [weeklyAssign.days],
  );

  const weeklyAssignHours = useMemo(
    () => selectedAssignDays.reduce((total, day) => {
      const row = weeklyAssign.days[day];
      return total + scheduleHours(row.start_time, row.end_time);
    }, 0),
    [selectedAssignDays, weeklyAssign.days],
  );

  const weeklyAssignAmount = useMemo(
    () => weeklyAssignHours * Math.max(0, Number(weeklyAssign.per_hour_rate || 0)),
    [weeklyAssignHours, weeklyAssign.per_hour_rate],
  );

  const setClientField = (key, value) => setForm(current => ({ ...current, [key]: value }));
  const setScheduleField = (key, value) => setScheduleForm(current => ({ ...current, [key]: value }));

  const setAssignEmployee = employeeId => {
    const existingGroup = groupedSchedules.find(group => String(group.employee?.id) === String(employeeId));
    setWeeklyAssign(current => ({
      ...current,
      employee_id: employeeId,
      per_hour_rate: existingGroup?.rate ?? '',
      break_allowed_hours: existingGroup?.schedules?.[0]?.break_allowed_hours ?? current.break_allowed_hours ?? '0.30',
    }));
  };

  const setAssignDay = (day, patch) => {
    setWeeklyAssign(current => ({
      ...current,
      days: {
        ...current.days,
        [day]: { ...current.days[day], ...patch },
      },
    }));
  };

  const toggleAllAssignDays = checked => {
    setWeeklyAssign(current => ({
      ...current,
      days: WEEKDAYS.reduce((days, day) => {
        days[day] = { ...current.days[day], selected: checked };
        return days;
      }, {}),
    }));
  };

  const assignWeeklySchedules = async () => {
    if (!weeklyAssign.employee_id) {
      notify('error', 'Select a VA / Employee first.');
      return;
    }
    if (!selectedAssignDays.length) {
      notify('error', 'Select at least one weekday.');
      return;
    }
    if (weeklyAssign.per_hour_rate === '' || Number(weeklyAssign.per_hour_rate) < 0) {
      notify('error', 'Enter a valid VA per-hour rate.');
      return;
    }

    const breakCode = String(weeklyAssign.break_allowed_hours ?? '0.00');
    const breakParts = Number(breakCode).toFixed(2).split('.');
    if (Number(breakCode) < 0 || Number(breakCode) > 12.59 || Number(breakParts[1] || 0) > 59) {
      notify('error', 'Enter break time in H.MM format, for example 0.30 = 30 minutes.');
      return;
    }

    const employee = selectedAssignEmployee;
    const existingForEmployee = schedules.filter(schedule => String(schedule.employee_id) === String(weeklyAssign.employee_id));
    setBusy(true);
    try {
      await Promise.all(selectedAssignDays.map(day => {
        const row = weeklyAssign.days[day];
        const payload = {
          employee_id: weeklyAssign.employee_id,
          client_id: clientId,
          weekday: day,
          start_time: row.start_time,
          end_time: row.end_time,
          timezone: employee?.timezone || 'Asia/Karachi',
          time_basis: 'employee',
          per_hour_rate: weeklyAssign.per_hour_rate,
          break_allowed_hours: weeklyAssign.break_allowed_hours || '0.00',
          enabled: true,
          repeat: !!row.repeat,
          schedule_date: null,
        };
        const existing = existingForEmployee.find(schedule => String(schedule.employee_weekday || schedule.weekday).toLowerCase() === day.toLowerCase());
        return existing
          ? api.put(`/management/schedules/${existing.id}`, payload)
          : api.post('/management/schedules', payload);
      }));
      notify('success', `${selectedAssignEmployee?.first_name || 'VA'} assigned. ${selectedAssignDays.length} weekly shift(s) saved at ${money(weeklyAssign.per_hour_rate)}/hr.`);
      await loadCore(true);
      navigate(`/admin/client/${clientId}/schedule`);
    } catch (error) {
      notify('error', messageFrom(error));
    } finally {
      setBusy(false);
    }
  };

  const saveClient = async () => {
    setBusy(true);
    try {
      const response = await api.put(`/admin/clients/${clientId}`, clientPayload(form));
      notify('success', response.data?.message || 'Client updated.');
      await loadCore(true);
    } catch (error) {
      notify('error', messageFrom(error));
    } finally {
      setBusy(false);
    }
  };

  const openAddSchedule = (employeeId = '') => {
    const employee = (options.employees || []).find(item => String(item.id) === String(employeeId));
    setEditingSchedule(null);
    setScheduleForm({
      ...emptySchedule,
      employee_id: employeeId || '',
      per_hour_rate: client?.per_hour_charges || '',
      break_allowed_hours: '0.30',
      timezone: employee?.timezone || 'Asia/Karachi',
    });
    setScheduleOpen(true);
  };

  const openEditSchedule = schedule => {
    setEditingSchedule(schedule);
    setScheduleForm({
      employee_id: String(schedule.employee_id || schedule.employee?.id || ''),
      date: schedule.schedule_date ? String(schedule.schedule_date).slice(0, 10) : '',
      weekday: weekdayInZone(schedule.shift_starts_at, schedule.employee_timezone || schedule.employee?.timezone) || schedule.employee_weekday || schedule.weekday || 'Monday',
      start_time: timeInZone(schedule.shift_starts_at, schedule.employee_timezone || schedule.employee?.timezone) || String(schedule.start_time || '09:00').slice(0, 5),
      end_time: timeInZone(schedule.shift_ends_at, schedule.employee_timezone || schedule.employee?.timezone) || String(schedule.end_time || '17:00').slice(0, 5),
      per_hour_rate: schedule.per_hour_rate ?? client?.per_hour_charges ?? '',
      break_allowed_hours: schedule.break_allowed_hours ?? '0.30',
      enabled: !!schedule.enabled,
      repeat: schedule.repeat === undefined ? true : !!schedule.repeat,
      timezone: schedule.employee_timezone || schedule.timezone || schedule.employee?.timezone || 'Asia/Karachi',
    });
    setScheduleOpen(true);
  };

  const saveSchedule = async () => {
    const employee = (options.employees || []).find(item => String(item.id) === String(scheduleForm.employee_id));
    const payload = {
      employee_id: scheduleForm.employee_id,
      client_id: clientId,
      weekday: scheduleForm.weekday,
      schedule_date: scheduleForm.repeat ? null : (scheduleForm.date || null),
      start_time: scheduleForm.start_time,
      end_time: scheduleForm.end_time,
      timezone: employee?.timezone || scheduleForm.timezone || 'Asia/Karachi',
      time_basis: 'employee',
      per_hour_rate: scheduleForm.per_hour_rate === '' ? null : scheduleForm.per_hour_rate,
      break_allowed_hours: scheduleForm.break_allowed_hours || '0.00',
      enabled: !!scheduleForm.enabled,
      repeat: !!scheduleForm.repeat,
    };

    setBusy(true);
    try {
      if (editingSchedule) {
        await api.put(`/management/schedules/${editingSchedule.id}`, payload);
        notify('success', 'Schedule updated.');
      } else {
        await api.post('/management/schedules', payload);
        notify('success', 'VA assigned and schedule created.');
      }
      setScheduleOpen(false);
      await loadCore(true);
      if (activeTab === 'assign') goTab('schedule');
    } catch (error) {
      notify('error', messageFrom(error));
    } finally {
      setBusy(false);
    }
  };

  const removeSchedule = async schedule => {
    setBusy(true);
    try {
      await api.delete(`/management/schedules/${schedule.id}`);
      notify('success', 'Schedule deleted.');
      await loadCore(true);
    } catch (error) {
      notify('error', messageFrom(error));
    } finally {
      setBusy(false);
    }
  };

  const applyReportFilters = () => {
    setReportPage(1);
    loadReport(reportFilters, 1);
  };

  const resetReportFilters = () => {
    const clean = { employee_id: '', from: '', to: '', search: '' };
    setReportFilters(clean);
    setReportPage(1);
    loadReport(clean, 1);
  };

  if (loading && !client) return <Loading />;
  if (!client) return null;

  const renderProfile = () => (
    <Card className="client-workspace-panel" title="Client details" subtitle="Identity, billing defaults and account classification.">
      <div className="client-profile-form-grid">
        <Input label="Client / Doctor name *" value={form.name || ''} onChange={event => setClientField('name', event.target.value)} />
        <Input label="Email *" type="email" value={form.email || ''} onChange={event => setClientField('email', event.target.value)} />
        <Input label="Business / Practice" value={form.business_name || ''} onChange={event => setClientField('business_name', event.target.value)} />
        <Input label="Phone" value={form.phone_number || ''} onChange={event => setClientField('phone_number', event.target.value)} />
        <Input label="Country" value={form.country || ''} onChange={event => setClientField('country', event.target.value)} />
        <Input label="Default rate / hour" type="number" step="0.01" value={form.per_hour_charges ?? ''} onChange={event => setClientField('per_hour_charges', event.target.value)} />
        <Select label="Service" value={form.service_id || ''} onChange={event => setClientField('service_id', event.target.value)}>
          <option value="">Select service</option>
          {(options.services || []).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
        </Select>
        <Select label="Contract type" value={form.contract_type_id || ''} onChange={event => setClientField('contract_type_id', event.target.value)}>
          <option value="">Select contract</option>
          {(options.contracts || []).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
        </Select>
      </div>
      <label className="client-ring-toggle"><input type="checkbox" checked={!!form.ring_center} onChange={event => setClientField('ring_center', event.target.checked)} /><span>Ring Center enabled</span></label>
      <div className="client-panel-actions"><Button icon={Save} loading={busy} onClick={saveClient}>Save profile</Button></div>
    </Card>
  );

  const renderTimezone = () => (
    <Card className="client-workspace-panel" title="Client timezone" subtitle="Doctor-facing times and converted schedule previews use this timezone.">
      <div className="client-timezone-layout">
        <TimezonePicker
          label="Timezone *"
          value={form.timezone || ''}
          onChange={value => setClientField('timezone', value)}
          onCitySelect={place => setForm(current => ({ ...current, timezone: place.timezone, country: place.country || current.country }))}
        />
        <div className="timezone-summary-card">
          <Clock3 size={24} />
          <span>Assigned timezone</span>
          <strong>{form.timezone || 'Not assigned'}</strong>
          <small>This is the timezone used when CareVMA shows Doctor / Client local schedule time.</small>
        </div>
      </div>
      <div className="client-panel-actions"><Button icon={Save} loading={busy} onClick={saveClient}>Save timezone</Button></div>
    </Card>
  );

  const renderSchedule = () => (
    <div className="client-schedule-workspace">
      <section className="client-schedule-hero">
        <div className="client-schedule-hero-copy">
          <span className="client-section-kicker">Schedule operations</span>
          <h2>VA schedules for {client.name}</h2>
          <p>Manage weekly coverage, hourly rates, VA timezones and assignment status from one place.</p>
        </div>
        <div className="client-schedule-hero-actions">
          <Button variant="secondary" icon={UsersRound} onClick={() => goTab('assign')}>Assign VA</Button>
          <Button icon={Plus} onClick={() => openAddSchedule()}>Add schedule</Button>
        </div>
      </section>

      <section className="client-schedule-metrics" aria-label="Schedule summary">
        <div className="client-schedule-metric">
          <div className="client-schedule-metric-icon"><UsersRound size={19} /></div>
          <div><span>Assigned VAs</span><strong>{scheduleStats.assigned}</strong><small>Unique team members</small></div>
        </div>
        <div className="client-schedule-metric">
          <div className="client-schedule-metric-icon"><Activity size={19} /></div>
          <div><span>Active shifts</span><strong>{scheduleStats.active}</strong><small>Enabled weekly schedules</small></div>
        </div>
        <div className="client-schedule-metric">
          <div className="client-schedule-metric-icon"><Clock3 size={19} /></div>
          <div><span>Weekly duration</span><strong>{durationHM(scheduleStats.hours)} H.MM</strong><small>Across current schedules</small></div>
        </div>
        <div className="client-schedule-metric client-schedule-metric-wide">
          <div className="client-schedule-metric-icon"><Globe2 size={19} /></div>
          <div><span>Client timezone</span><strong>{scheduleStats.timezone}</strong><small>Doctor-facing converted time</small></div>
        </div>
      </section>

      <section className="client-schedule-toolbar">
        <div className="client-schedule-search">
          <Search size={18} />
          <input
            type="search"
            value={scheduleSearch}
            onChange={event => setScheduleSearch(event.target.value)}
            placeholder="Search VA, day, time or timezone..."
            aria-label="Search schedules"
          />
        </div>
        <Select label="Status" value={scheduleStatus} onChange={event => setScheduleStatus(event.target.value)}>
          <option value="all">All schedules</option>
          <option value="enabled">Enabled only</option>
          <option value="disabled">Disabled only</option>
        </Select>
      </section>

      {!groupedSchedules.length ? (
        <Card className="client-workspace-panel client-schedule-empty-card">
          <div className="client-empty-workspace">
            <div className="client-empty-icon"><CalendarDays size={28} /></div>
            <h3>No VA schedules yet</h3>
            <p>Assign a VA and create the first weekly shift for this client.</p>
            <div className="client-empty-actions">
              <Button variant="secondary" icon={UsersRound} onClick={() => goTab('assign')}>Assign VA</Button>
              <Button icon={Plus} onClick={() => openAddSchedule()}>Add schedule</Button>
            </div>
          </div>
        </Card>
      ) : !filteredScheduleGroups.length ? (
        <Card className="client-workspace-panel client-schedule-empty-card">
          <div className="client-empty-workspace">
            <div className="client-empty-icon"><Search size={28} /></div>
            <h3>No matching schedules</h3>
            <p>Try another search term or reset the status filter.</p>
            <Button variant="secondary" onClick={() => { setScheduleSearch(''); setScheduleStatus('all'); }}>Reset filters</Button>
          </div>
        </Card>
      ) : filteredScheduleGroups.map(group => {
        const initials = `${group.employee?.first_name?.[0] || ''}${group.employee?.last_name?.[0] || ''}`.toUpperCase() || 'VA';
        const enabledCount = group.schedules.filter(schedule => !!schedule.enabled).length;
        return (
          <Card key={group.employee?.id || group.employee?.employee_code || `${group.employee?.email}-${group.schedules[0]?.id}`} className="client-va-schedule-card">
            <div className="client-va-header">
              <div className="client-va-identity">
                <div className="client-va-avatar">{initials}</div>
                <div>
                  <div className="client-va-title-line">
                    <strong>{group.employee?.first_name} {group.employee?.last_name}</strong>
                    <Badge tone={enabledCount ? 'success' : 'neutral'}>{enabledCount ? `${enabledCount} active` : 'Inactive'}</Badge>
                  </div>
                  <span>{group.employee?.employee_code ? `${group.employee.employee_code} · ` : ''}{group.employee?.email || 'Assigned VA'}</span>
                </div>
              </div>
              <div className="client-va-header-right">
                <div className="client-va-timezone"><Clock3 size={15} /><span>{group.employee?.timezone || group.schedules[0]?.employee_timezone || group.schedules[0]?.timezone || 'Timezone not set'}</span></div>
                <div className="client-va-rate">{group.rate !== null && group.rate !== '' ? `${money(group.rate)}/hr` : 'Rate not set'}</div>
                <Button variant="secondary" icon={Plus} onClick={() => openAddSchedule(group.employee?.id)}>Add shift</Button>
              </div>
            </div>

            <div className="client-schedule-columns" aria-hidden="true">
              <span>VA day</span>
              <span>VA local time</span>
              <span>Doctor local time</span>
              <span>Duration</span>
              <span>Allowed break</span>
              <span>Status</span>
              <span>Actions</span>
            </div>

            <div className="client-schedule-list">
              {group.schedules.map(schedule => (
                <div className="client-schedule-row" key={schedule.id}>
                  <div className="client-schedule-cell" data-label="VA day"><strong className="schedule-day">{schedule.employee_weekday || weekdayInZone(schedule.shift_starts_at, schedule.employee_timezone || group.employee?.timezone) || schedule.weekday}</strong></div>
                  <div className="client-schedule-cell schedule-time" data-label="VA local time"><Clock3 size={15} /><span>{formatTime12(timeInZone(schedule.shift_starts_at, schedule.employee_timezone || group.employee?.timezone))} – {formatTime12(timeInZone(schedule.shift_ends_at, schedule.employee_timezone || group.employee?.timezone))}</span><small>{schedule.employee_timezone || group.employee?.timezone || 'VA timezone'}</small></div>
                  <div className="client-schedule-cell schedule-time" data-label="Doctor local time"><span>{formatTime12(schedule.start_time)} – {formatTime12(schedule.end_time)}</span><small>{schedule.client_timezone || client?.timezone || schedule.timezone || 'Doctor timezone'}</small></div>
                  <div className="client-schedule-cell" data-label="Duration"><span className="schedule-hours">{hoursText(schedule.no_of_hours)}</span></div>
                  <div className="client-schedule-cell" data-label="Allowed break"><span className="schedule-break-chip">{Number(schedule.break_allowed_hours || 0).toFixed(2)} H.MM</span></div>
                  <div className="client-schedule-cell" data-label="Status"><Badge tone={schedule.enabled ? 'success' : 'danger'}>{schedule.enabled ? 'Enabled' : 'Disabled'}</Badge></div>
                  <div className="client-row-menu" data-label="Actions">
                    <button type="button" title="Edit schedule" onClick={() => openEditSchedule(schedule)}><Pencil size={16} /></button>
                    <button type="button" className="danger" title="Delete schedule" onClick={() => removeSchedule(schedule)}><Trash2 size={16} /></button>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        );
      })}
    </div>
  );

  const renderAssign = () => {
    const allSelected = selectedAssignDays.length === WEEKDAYS.length;
    return (
      <div className="assign-va-workspace">
        <section className="assign-va-hero">
          <div>
            <span className="client-section-kicker">Workforce assignment</span>
            <h2>Employee Schedule</h2>
            <p>Select a VA and enter the shift in that VA's local timezone. CareVMA converts the same instant to the Client / Doctor timezone for check-in, attendance and billing.</p>
          </div>
          <div className="assign-va-rate-summary">
            <span>Estimated weekly cost</span>
            <strong>{money(weeklyAssignAmount)}</strong>
            <small>{weeklyAssignHours.toFixed(2)} hrs × {money(weeklyAssign.per_hour_rate || 0)}/hr</small>
          </div>
        </section>

        <Card className="client-workspace-panel assign-va-card">
          <div className="assign-va-employee-row">
            <Select label="Select Employee *" value={weeklyAssign.employee_id} onChange={event => setAssignEmployee(event.target.value)}>
              <option value="">Select value</option>
              {(options.employees || []).map(employee => (
                <option key={employee.id} value={employee.id}>
                  {employee.employee_code} · {employee.first_name} {employee.last_name}{assignedEmployeeIds.has(String(employee.id)) ? ' · assigned' : ''}
                </option>
              ))}
            </Select>
            <div className="assign-va-selected-meta">
              <span>VA timezone</span>
              <strong>{selectedAssignEmployee?.timezone || 'Select an employee'}</strong>
              <small>Enter times in this VA timezone. Doctor time is calculated automatically.</small>
            </div>
          </div>

          <div className="assign-va-section-title">
            <div>
              <strong>Weekdays Schedule</strong>
              <span>Select one or more days. Hours calculate automatically.</span>
            </div>
            <label className="assign-va-all-days">
              <input type="checkbox" checked={allSelected} onChange={event => toggleAllAssignDays(event.target.checked)} />
              <span>All Days</span>
            </label>
          </div>

          <div className="assign-va-grid assign-va-grid-head" aria-hidden="true">
            <span>Day</span>
            <span>Start Time</span>
            <span>End Time</span>
            <span>Timezone</span>
            <span>Duration (H.MM)</span>
            <span>Repeat</span>
          </div>

          <div className="assign-va-week-list">
            {WEEKDAYS.map(day => {
              const row = weeklyAssign.days[day];
              const hours = scheduleHours(row.start_time, row.end_time);
              return (
                <div className={`assign-va-grid assign-va-day-row ${row.selected ? 'is-selected' : ''}`} key={day}>
                  <label className="assign-va-day-check">
                    <input type="checkbox" checked={!!row.selected} onChange={event => setAssignDay(day, { selected: event.target.checked })} />
                    <strong>{day}</strong>
                  </label>
                  <Input type="time" value={row.start_time} onChange={event => setAssignDay(day, { start_time: event.target.value })} disabled={!row.selected} />
                  <Input type="time" value={row.end_time} onChange={event => setAssignDay(day, { end_time: event.target.value })} disabled={!row.selected} />
                  <div className="assign-va-timezone-field">{selectedAssignEmployee?.timezone || 'Select timezone'}</div>
                  <div className="assign-va-hours-field">{row.selected ? `${durationHM(hours)} H.MM` : '—'}</div>
                  <label className="assign-va-repeat">
                    <input type="checkbox" checked={!!row.repeat} onChange={event => setAssignDay(day, { repeat: event.target.checked })} disabled={!row.selected} />
                    <span>{row.repeat ? 'Weekly' : 'Once'}</span>
                  </label>
                </div>
              );
            })}
          </div>

          <div className="assign-va-footer">
            <div className="assign-va-rate-box">
              <Input
                label="VA Per Hour Rate *"
                type="number"
                min="0"
                step="0.01"
                value={weeklyAssign.per_hour_rate}
                onChange={event => setWeeklyAssign(current => ({ ...current, per_hour_rate: event.target.value }))}
                placeholder="0.00"
              />
              <Input
                label="Allowed Break (H.MM) *"
                type="number"
                min="0"
                max="12.59"
                step="0.01"
                value={weeklyAssign.break_allowed_hours}
                onChange={event => setWeeklyAssign(current => ({ ...current, break_allowed_hours: event.target.value }))}
                placeholder="0.30"
                hint="0.30 = 30 min · 0.50 = 50 min · 1.30 = 1 hr 30 min"
              />
              <div className="assign-va-rate-note">
                <strong>{money(weeklyAssign.per_hour_rate || 0)}/hr</strong>
                <span>Rate and break allowance are saved on the VA + Client schedule. Actual break taken is unpaid and reduces billable worked time.</span>
              </div>
            </div>

            <div className="assign-va-totals">
              <div><span>Selected days</span><strong>{selectedAssignDays.length}</strong></div>
              <div><span>Weekly duration</span><strong>{durationHM(weeklyAssignHours)} H.MM</strong></div>
              <div><span>Weekly amount</span><strong>{money(weeklyAssignAmount)}</strong></div>
            </div>

            <div className="assign-va-actions">
              <Button variant="ghost" onClick={() => goTab('schedule')}>Cancel</Button>
              <Button icon={UsersRound} loading={busy} onClick={assignWeeklySchedules} disabled={!weeklyAssign.employee_id || !selectedAssignDays.length}>Assign Employee</Button>
            </div>
          </div>
        </Card>
      </div>
    );
  };

  const reportColumns = [
    { key: 'employee', label: 'VA', render: row => `${row.employee?.first_name || ''} ${row.employee?.last_name || ''}`.trim() || '—' },
    { key: 'date', label: 'Date', render: row => <DualDate value={row.clock_in || row.date} doctorTz={row.client_timezone || client?.timezone || row.client?.timezone || 'UTC'} vaTz={row.employee_timezone || row.employee?.timezone || 'UTC'} /> },
    { key: 'clock_in', label: 'Clock in', render: row => <DualTime value={row.clock_in} doctorTz={row.client_timezone || client?.timezone || row.client?.timezone || 'UTC'} vaTz={row.employee_timezone || row.employee?.timezone || 'UTC'} /> },
    { key: 'clock_out', label: 'Clock out', render: row => <DualTime value={row.clock_out} doctorTz={row.client_timezone || client?.timezone || row.client?.timezone || 'UTC'} vaTz={row.employee_timezone || row.employee?.timezone || 'UTC'} live={!row.clock_out} /> },
    { key: 'worked', label: 'Worked', render: row => mins(row.total_minutes) },
    { key: 'break', label: 'Break', render: row => `${Math.floor(Number(row.break_taken || 0) / 60)} min` },
    { key: 'late', label: 'Late', render: row => `${Math.round(Number(row.late_minutes || 0))} min` },
    { key: 'overtime', label: 'Overtime', render: row => `${Math.round(Number(row.overtime || 0))} min` },
  ];

  const renderReport = () => (
    <Card className="client-workspace-panel" title="Daily Work Report" subtitle="Doctor time is the billing/calculation source; VA time is the same instant converted to the employee timezone.">
      <div className="client-report-filters">
        <Select label="VA" value={reportFilters.employee_id} onChange={event => setReportFilters(current => ({ ...current, employee_id: event.target.value }))}>
          <option value="">All assigned VAs</option>
          {assignedEmployees.map(employee => <option key={employee.id} value={employee.id}>{employee.first_name} {employee.last_name}</option>)}
        </Select>
        <Input label="From" type="date" value={reportFilters.from} onChange={event => setReportFilters(current => ({ ...current, from: event.target.value }))} />
        <Input label="To" type="date" value={reportFilters.to} onChange={event => setReportFilters(current => ({ ...current, to: event.target.value }))} />
        <Input label="Search" value={reportFilters.search} onChange={event => setReportFilters(current => ({ ...current, search: event.target.value }))} placeholder="Date, VA or time" />
        <div className="client-report-actions"><Button onClick={applyReportFilters}>Filter</Button><Button variant="ghost" onClick={resetReportFilters}>Reset</Button></div>
      </div>
      {reportLoading && !reportRows.length ? <Loading /> : <Table columns={reportColumns} rows={reportRows} localPagination={false} emptyTitle="No work records" emptyDescription="No attendance is linked to this client for the selected filters." />}
      <Pagination meta={reportMeta} onPage={setReportPage} />
    </Card>
  );

  return (
    <div className="client-profile-workspace">
      <div className="client-profile-page-head">
        <div>
          <div className="eyebrow">Client workspace</div>
          <h1>Client Profile - {client.name}</h1>
          <p>Manage client details, timezone, VA schedules and daily work records.</p>
        </div>
        <div className="client-profile-page-actions">
          {activeTab === 'schedule'
            ? <Button variant="secondary" icon={Plus} onClick={() => openAddSchedule()}>Add schedule</Button>
            : <Button variant="secondary" icon={Plus} onClick={() => goTab('assign')}>Assign VA</Button>}
          <Button icon={ArrowLeft} onClick={() => navigate('/admin/clients')}>Clients</Button>
        </div>
      </div>

      {notice && <Notice type={notice.type} onClose={() => setNotice(null)}>{notice.text}</Notice>}

      <div className="client-profile-shell">
        <aside className="client-profile-tabs" aria-label="Client profile sections">
          {TABS.map(tab => {
            const Icon = tab.icon;
            return <button key={tab.key} type="button" className={activeTab === tab.key ? 'active' : ''} onClick={() => goTab(tab.key)}><Icon size={20} /><span>{tab.label}</span></button>;
          })}
        </aside>

        <main className="client-profile-content">
          {activeTab === 'profile' && renderProfile()}
          {activeTab === 'timezone' && renderTimezone()}
          {activeTab === 'schedule' && renderSchedule()}
          {activeTab === 'assign' && renderAssign()}
          {activeTab === 'report' && renderReport()}
        </main>
      </div>

      {scheduleOpen && (
        <div className="client-schedule-drawer-backdrop" onMouseDown={event => event.target === event.currentTarget && setScheduleOpen(false)}>
          <div className="client-schedule-drawer">
            <div className="client-schedule-drawer-head"><div><span>{editingSchedule ? 'Edit assignment' : 'New assignment'}</span><h2>{editingSchedule ? 'Edit schedule' : 'Add schedule'}</h2></div><button type="button" onClick={() => setScheduleOpen(false)}>×</button></div>
            <div className="client-profile-form-grid one-column">
              <Select label="VA / Employee *" value={scheduleForm.employee_id} onChange={event => {
                const employeeId = event.target.value;
                const employee = (options.employees || []).find(item => String(item.id) === String(employeeId));
                setScheduleForm(current => ({ ...current, employee_id: employeeId, timezone: employee?.timezone || current.timezone }));
              }}>
                <option value="">Select VA</option>
                {(options.employees || []).map(employee => <option key={employee.id} value={employee.id}>{employee.employee_code} · {employee.first_name} {employee.last_name}</option>)}
              </Select>
              <Input label={scheduleForm.repeat ? 'Date (optional while weekly)' : 'Date *'} type="date" value={scheduleForm.date || ''} disabled={!!scheduleForm.repeat} onChange={event => { const date = event.target.value; const weekday = weekdayFromDate(date); setScheduleForm(current => ({ ...current, date, weekday: weekday || current.weekday, repeat: date ? false : current.repeat })); }} hint="For a one-time shift, select the exact VA-local date; weekday is set automatically." />
              <Select label="Day *" value={scheduleForm.weekday} onChange={event => setScheduleField('weekday', event.target.value)}>{WEEKDAYS.map(day => <option key={day} value={day}>{day}</option>)}</Select>
              <Input label="Start time *" type="time" value={scheduleForm.start_time} onChange={event => setScheduleField('start_time', event.target.value)} />
              <Input label="End time *" type="time" value={scheduleForm.end_time} onChange={event => setScheduleField('end_time', event.target.value)} />
              <Input label="Rate / hour" type="number" min="0" step="0.01" value={scheduleForm.per_hour_rate} onChange={event => setScheduleField('per_hour_rate', event.target.value)} />
              <Input label="Allowed Break (H.MM) *" type="number" min="0" max="12.59" step="0.01" value={scheduleForm.break_allowed_hours || '0.00'} onChange={event => setScheduleField('break_allowed_hours', event.target.value)} hint="0.30 = 30 min · 0.50 = 50 min" />
              <Input label="VA timezone" value={(options.employees || []).find(item => String(item.id) === String(scheduleForm.employee_id))?.timezone || scheduleForm.timezone || 'Select a VA'} disabled />
              <label className="client-ring-toggle"><input type="checkbox" checked={!!scheduleForm.repeat} onChange={event => setScheduleForm(current => ({ ...current, repeat: event.target.checked, date: event.target.checked ? '' : current.date }))} /><span>Repeat weekly</span></label>
            </div>
            <label className="client-ring-toggle"><input type="checkbox" checked={!!scheduleForm.enabled} onChange={event => setScheduleField('enabled', event.target.checked)} /><span>Schedule enabled</span></label>
            <div className="client-schedule-drawer-actions"><Button variant="ghost" onClick={() => setScheduleOpen(false)}>Cancel</Button><Button loading={busy} icon={Save} disabled={!scheduleForm.employee_id} onClick={saveSchedule}>{editingSchedule ? 'Save schedule' : 'Add schedule'}</Button></div>
          </div>
        </div>
      )}
    </div>
  );
}
