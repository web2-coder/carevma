import { useEffect, useState } from 'react';
import '../../css/live-session-timer.css';
import '../../css/dual-timezone-shift.css';
import '../../css/dual-timezone-tables.css';
import { Coffee, LogIn, LogOut, TimerReset, Upload, Trash2, Save, KeyRound, Link2, CalendarDays, Clock3 } from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { formatDate, formatDateTime, formatTime, formatWeekday, zonedNowParts } from '../timezone';
import { PageHeader, Card, Button, Input, Select, Textarea, Badge, statusTone, Notice, Table, Pagination, Loading, Modal, mins, dateTime, timeValue, AvatarMedia } from '../components/ui';

function useNotice(){const [notice,setNotice]=useState(null);return{notice,success:t=>t&&setNotice({type:'success',text:t}),error:t=>t&&setNotice({type:'error',text:t}),clear:()=>setNotice(null)}}
function NB({n}){return n.notice?<Notice type={n.notice.type} onClose={n.clear}>{n.notice.text}</Notice>:null}

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

function enabledValue(value){
  if(typeof value==='boolean') return value;
  if(typeof value==='number') return value===1;
  return ['1','true','enabled','yes','on'].includes(String(value??'').trim().toLowerCase());
}

function zonedParts(timeZone){const p=zonedNowParts(timeZone||'UTC');return {weekday:p.weekday,hour:p.hour,minute:p.minute}}


function hmsSeconds(value) {
  const [h = '0', m = '0', s = '0'] = String(
    value || '00:00:00'
  ).split(':');

  return Math.max(
    0,
    (Number(h) || 0) * 3600 +
    (Number(m) || 0) * 60 +
    (Number(s) || 0)
  );
}

function durationText(total) {
  total = Math.max(0, Math.floor(Number(total) || 0));

  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const seconds = total % 60;

  return [
    String(hours).padStart(2, '0'),
    String(minutes).padStart(2, '0'),
    String(seconds).padStart(2, '0'),
  ].join(':');
}
function BreakUsagePanel({active,activeBreak,serverNow}){
  const initialCurrent=Math.max(0,Number(active?.current_break_elapsed_seconds||0));
  const [current,setCurrent]=useState(initialCurrent);

  useEffect(()=>{
    if(!activeBreak){setCurrent(0);return undefined}

    // The backend calculates the active break duration from UTC timestamps.
    // Use that value as the anchor so refreshes and browser timezone settings
    // cannot reset or offset the break timer.
    const baseSeconds=Math.max(0,Number(active?.current_break_elapsed_seconds||0));
    const anchor=performance.now();
    const tick=()=>setCurrent(baseSeconds+Math.max(0,Math.floor((performance.now()-anchor)/1000)));

    tick();
    const id=setInterval(tick,1000);
    return()=>clearInterval(id);
  },[active?.id,active?.current_break_elapsed_seconds,activeBreak?.id]);

  const completed=Math.max(0,Number(active?.completed_break_seconds??active?.break_taken??0));
  const used=completed+(activeBreak?current:0);
  const limit=hmsSeconds(active?.break_limit);
  const remaining=limit?Math.max(0,limit-used):null;

  return <div className={`break-usage-panel ${activeBreak?'is-active':''}`}>
    <div className="break-usage-head"><div><span>Break status</span><strong>{activeBreak?'Currently on break':'Working'}</strong></div>{activeBreak&&<div className="break-live-counter"><span>Current break</span><strong>{durationText(current)}</strong></div>}</div>
    <div className="break-usage-grid"><div><span>Total break used</span><strong>{durationText(used)}</strong></div><div><span>Allowed break</span><strong>{limit?durationText(limit):'Not set'}</strong></div><div><span>Remaining</span><strong>{remaining===null?'—':durationText(remaining)}</strong></div></div>
  </div>;
}

function LiveSessionCounter({active, serverNow, activeBreak}){
  const initialElapsed=Math.max(0,Number(active?.session_elapsed_seconds??active?.elapsed_seconds??0));
  const [elapsed,setElapsed]=useState(initialElapsed);

  useEffect(()=>{
    if(!active?.clock_in){setElapsed(0);return undefined}

    // Never restart from zero after refresh, Start Break or End Break.
    // Backend session_elapsed_seconds is measured from the original clock-in.
    let baseSeconds=Math.max(0,Number(active?.session_elapsed_seconds??active?.elapsed_seconds??0));

    // Compatibility fallback for an older API response: both values represent
    // absolute instants, so their difference is timezone-independent.
    if(baseSeconds===0){
      const nowMs=Date.parse(serverNow||'');
      const clockInMs=Date.parse(active?.clock_in_utc||active?.clock_in||'');
      if(Number.isFinite(nowMs)&&Number.isFinite(clockInMs)&&nowMs>=clockInMs){
        baseSeconds=Math.floor((nowMs-clockInMs)/1000);
      }
    }

    const anchor=performance.now();
    const tick=()=>setElapsed(baseSeconds+Math.max(0,Math.floor((performance.now()-anchor)/1000)));
    tick();
    const timer=setInterval(tick,1000);
    return()=>clearInterval(timer);
  },[active?.id,active?.clock_in,active?.clock_in_utc,active?.session_elapsed_seconds,active?.elapsed_seconds,serverNow]);

  const hours=Math.floor(elapsed/3600);
  const minutes=Math.floor((elapsed%3600)/60);
  const seconds=elapsed%60;
  const pad=value=>String(value).padStart(2,'0');

  return <div className={`live-session-counter${activeBreak?' is-break':''}`}>
    <div className="live-counter-topline">
      <div className="live-counter-state"><span className="pulse-dot"/><strong>{activeBreak?'On break':'Working now'}</strong></div>
      <span>Live session time</span>
    </div>
    <div className="live-counter-digits" aria-label={`${hours} hours ${minutes} minutes ${seconds} seconds`}>
      <div className="live-counter-segment"><strong>{pad(hours)}</strong><span>Hours</span></div>
      <b>:</b>
      <div className="live-counter-segment"><strong>{pad(minutes)}</strong><span>Minutes</span></div>
      <b>:</b>
      <div className="live-counter-segment"><strong>{pad(seconds)}</strong><span>Seconds</span></div>
    </div>
    <p>{activeBreak?'Break is active. Session time continues from your original clock-in.':'Counter continues from your original clock-in and survives page refreshes.'}</p>
  </div>;
}

function clientScheduleContext(schedule,fallbackTimezone){
  if(!enabledValue(schedule.enabled)) return null;
  const timezone=schedule.client_timezone||schedule.client?.timezone||schedule.calculation_timezone||schedule.timezone||fallbackTimezone||'UTC';
  const now=zonedParts(timezone);
  if(String(schedule.weekday||'').trim().toLowerCase()!==String(now.weekday||'').trim().toLowerCase()) return null;
  const toMinutes=value=>{const [h='0',m='0']=String(value||'00:00').split(':');return Number(h)*60+Number(m)};
  const current=now.hour*60+now.minute,start=toMinutes(schedule.start_time),endRaw=toMinutes(schedule.end_time);
  const overnight=endRaw<=start;
  const end=overnight?endRaw+1440:endRaw;
  const currentForWindow=overnight&&current<endRaw?current+1440:current;
  const opens=start-15;
  const status=currentForWindow<opens?'early':currentForWindow>=end?'ended':'open';
  if(status!=='open') return null;
  return {...schedule,check_in_status:status};
}


function formatZone(value,timeZone,{seconds=false,weekday=true}={}){
  if(!value||!timeZone)return '—';
  try{
    return new Intl.DateTimeFormat('en-US',{
      timeZone,
      ...(weekday?{weekday:'short',month:'short',day:'numeric'}:{}),
      hour:'numeric',minute:'2-digit',...(seconds?{second:'2-digit'}:{}),hour12:true,
    }).format(new Date(value));
  }catch{return '—'}
}
function formatZoneTime(value,timeZone){
  if(!value||!timeZone)return '—';
  try{return new Intl.DateTimeFormat('en-US',{timeZone,hour:'numeric',minute:'2-digit',hour12:true}).format(new Date(value))}catch{return '—'}
}
function DualTimezoneClock({serverNow,employeeTimezone,clientTimezone}){
  const [nowMs,setNowMs]=useState(()=>Date.parse(serverNow||'')||Date.now());
  useEffect(()=>{
    const parsed=Date.parse(serverNow||'');
    const anchor=Number.isFinite(parsed)?parsed:Date.now();
    const perf=performance.now();
    const tick=()=>setNowMs(anchor+Math.max(0,performance.now()-perf));
    tick();const id=setInterval(tick,1000);return()=>clearInterval(id);
  },[serverNow]);
  const iso=new Date(nowMs).toISOString();
  return <div className="dual-timezone-clock">
    <div><span>Your live time</span><strong>{formatZone(iso,employeeTimezone,{seconds:true})}</strong><small>{employeeTimezone}</small></div>
    <div><span>Doctor live time</span><strong>{clientTimezone?formatZone(iso,clientTimezone,{seconds:true}):'—'}</strong><small>{clientTimezone||'No client timezone'}</small></div>
  </div>;
}
function scheduleRange(start,end,timeZone){
  if(!start||!end||!timeZone)return '—';
  return `${formatZone(start,timeZone)} – ${formatZoneTime(end,timeZone)}`;
}

export function EmployeeDashboard(){
  const [data,setData]=useState(null),[busy,setBusy]=useState(false);const n=useNotice();
  const load=async()=>{setBusy(true);try{const {data:response}=await api.get('/employee/dashboard');setData({...response,today_schedules:Array.isArray(response.today_schedules)?response.today_schedules:[]})}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
  useEffect(()=>{load();const timer=setInterval(load,30000);const onFocus=()=>load();const onVisibility=()=>{if(document.visibilityState==='visible')load()};window.addEventListener('focus',onFocus);document.addEventListener('visibilitychange',onVisibility);return()=>{clearInterval(timer);window.removeEventListener('focus',onFocus);document.removeEventListener('visibilitychange',onVisibility)}},[]);
  const action=async(fn,payload={})=>{setBusy(true);try{const {data:r}=await api.post(fn,payload);n.success(r.message);await load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
  if(!data)return <Loading/>;
  const active=data.active_attendance;const activeBreak=active?.breaks?.find(b=>!b.end_time);const activeClientTimezone=active?.client?.timezone||null;
  return <>
    <PageHeader eyebrow="My workday" title={`Welcome, ${data.employee.first_name}`} description="Your shift is calculated from the Client / Doctor timezone. CareVMA converts the same instant to your VA-local timezone."/>
    <NB n={n}/>
    <div className="employee-dashboard-pro">
      <Card className="shift-control-card" title="Shift control" subtitle={active?'Your attendance session is active':'Check-in appears 15 minutes before the Doctor-local shift start'}>
        <div className="shift-control-pro-grid">
          <div className="shift-control-main">
            {active?<div className="shift-control">
              <LiveSessionCounter active={active} serverNow={data.now_utc||data.now} activeBreak={activeBreak}/>
              <BreakUsagePanel active={active} activeBreak={activeBreak} serverNow={data.now_utc||data.now}/>
              <div className="shift-actions">{activeBreak?<Button variant="warning" loading={busy} icon={Coffee} onClick={()=>action('/employee/break/end')}>End break</Button>:<Button variant="secondary" loading={busy} icon={Coffee} onClick={()=>action('/employee/break/start',{notes:'Employee break'})}>Start break</Button>}<Button variant="danger" loading={busy} icon={LogOut} onClick={()=>action('/employee/clock-out')}>Clock out</Button></div>
              <div className="session-stats"><div><span>Break records</span><strong>{active.breaks?.length||0}</strong></div><div><span>Late</span><strong>{Math.max(0,Math.floor(Number(active.late_minutes||0)))} min</strong></div><div><span>Client</span><strong>{active.client?.name||'—'}</strong></div></div>
            </div>:<div className="schedule-picker">
              {data.today_schedules.length?data.today_schedules.map(s=>{
                const doctorTz=s.client_timezone||s.client?.timezone;const vaTz=s.employee_timezone||data.timezone;
                return <div className="schedule-choice-card" key={s.id}>
                  <div className="schedule-choice-copy"><span>{s.client?.name||'Client'}</span><strong>{scheduleRange(s.client_shift_starts_at,s.client_shift_ends_at,doctorTz)}</strong><small>Doctor time · {doctorTz||'Not set'}</small><small>Your local: {scheduleRange(s.shift_starts_at,s.shift_ends_at,vaTz)} · {vaTz}</small><small>Check-in opens: {formatZone(s.doctor_check_in_opens_at||s.client_shift_starts_at,doctorTz)} · Doctor</small><small>Your local opening: {formatZone(s.check_in_opens_at,vaTz)}</small></div>
                  <Button loading={busy} icon={LogIn} onClick={()=>action('/employee/clock-in',{schedule_id:s.id})}>Check in</Button>
                </div>
              }):<div className="empty-compact"><strong>No shift open right now</strong><span>Your next check-in option appears 15 minutes before the Doctor-local start time.</span></div>}
            </div>}
          </div>
          <aside className="shift-control-side" aria-label="Workday status">
            {active&&<div className="shift-live-summary-card">
              <div className="shift-live-summary-head"><span className="pulse-dot"/><div><span>Live work session</span><strong>{active.client?.name||'Assigned client'}</strong><small>Started {dateTime(active.clock_in_utc||active.clock_in,data.timezone)}</small></div></div>
              <DualTimezoneClock serverNow={data.now_utc||data.now} employeeTimezone={data.timezone} clientTimezone={activeClientTimezone}/>
            </div>}
            <div className="shift-side-card">
              <span className="shift-side-icon"><Clock3 size={19}/></span>
              <div><span>VA timezone</span><strong>{data.timezone}</strong><small>Your converted local display</small></div>
            </div>
            <div className={`shift-side-card ${data.today_schedules.length?'is-ready':''}`}>
              <span className="shift-side-icon"><CalendarDays size={19}/></span>
              <div><span>Available shifts</span><strong>{data.today_schedules.length}</strong><small>Doctor-local 15-minute window</small></div>
            </div>
            <div className={`shift-side-card ${active?'is-live':''}`}>
              <span className="shift-side-icon"><TimerReset size={19}/></span>
              <div><span>Session</span><strong>{active?'Live':data.today_schedules.length?'Ready':data.completed_today?'Completed':'Not started'}</strong><small>{active?'Clocked in now':data.today_schedules.length?'A shift is ready':'Current work state'}</small></div>
            </div>
          </aside>
        </div>
      </Card>
      {!active&&data.today_schedules.length===0&&Array.isArray(data.next_schedules)&&data.next_schedules.length>0&&<Card className="available-now-card" title="Next shift" subtitle="Check-in eligibility is calculated from the Doctor / Client timezone; your local opening time is the same instant converted to your VA timezone.">
        <div className="agenda">{data.next_schedules.slice(0,1).map(s=>{const doctorTz=s.client_timezone||s.client?.timezone;const vaTz=s.employee_timezone||data.timezone;return <div className="agenda-row" key={`next-${s.id}`}><div className="agenda-time">{formatZoneTime(s.shift_starts_at,vaTz)}</div><div><strong>{s.client?.name||'Client'}</strong><span>Doctor shift: {scheduleRange(s.client_shift_starts_at,s.client_shift_ends_at,doctorTz)} · {doctorTz}</span><span>Your VA time: {scheduleRange(s.shift_starts_at,s.shift_ends_at,vaTz)} · {vaTz}</span><span>Check-in opens (Doctor): {formatZone(s.doctor_check_in_opens_at||s.client_shift_starts_at,doctorTz)}</span><span>Check-in opens (Your time): {formatZone(s.check_in_opens_at,vaTz)}</span></div><Badge tone="warning">Upcoming</Badge></div>})}</div>
      </Card>}
      {!active&&data.today_schedules.length>0&&<Card className="available-now-card" title="Available now" subtitle="Doctor time controls the shift; your VA-local converted time is shown underneath">
        <div className="agenda">{data.today_schedules.map(s=>{const doctorTz=s.client_timezone||s.client?.timezone;const vaTz=s.employee_timezone||data.timezone;return <div className="agenda-row" key={s.id}><div className="agenda-time">{formatZoneTime(s.client_shift_starts_at,doctorTz)}</div><div><strong>{s.client?.name}</strong><span>Doctor: {scheduleRange(s.client_shift_starts_at,s.client_shift_ends_at,doctorTz)} · {doctorTz}</span><span>Your local: {scheduleRange(s.shift_starts_at,s.shift_ends_at,vaTz)} · {vaTz}</span></div><Badge tone="success">Ready</Badge></div>})}{!data.today_schedules.length&&<div className="empty-compact">No shift is inside its check-in window right now.</div>}</div>
      </Card>}
    </div>
  </>;
}

export function EmployeeSchedule(){
  const [rows,setRows]=useState(null);const [filters,setFilters]=useState({search:'',day:'',status:'',timezone:''});const n=useNotice();
  useEffect(()=>{api.get('/employee/schedules').then(r=>setRows(Array.isArray(r.data)?r.data:[])).catch(e=>{n.error(messageFrom(e));setRows([])})},[]);
  if(!rows)return <Loading/>;
  const timezoneOptions=[...new Set(rows.map(r=>r.client_timezone||r.client?.timezone).filter(Boolean))].sort();const search=filters.search.trim().toLowerCase();
  const filteredRows=rows.filter(row=>{const enabled=enabledValue(row.enabled);const matchesSearch=!search||[row.weekday,row.client?.name,row.client?.business_name,row.client_timezone,row.employee_timezone,row.start_time,row.end_time].some(value=>String(value||'').toLowerCase().includes(search));const matchesDay=!filters.day||String(row.weekday||'')===filters.day;const matchesStatus=!filters.status||(filters.status==='enabled'?enabled:!enabled);const matchesTimezone=!filters.timezone||String(row.client_timezone||row.client?.timezone||'')===filters.timezone;return matchesSearch&&matchesDay&&matchesStatus&&matchesTimezone});
  const reset=()=>setFilters({search:'',day:'',status:'',timezone:''});
  const columns=[
    {key:'weekday',label:'Doctor day'},
    {key:'client',label:'Client / Doctor',render:r=>r.client?.name||'—'},
    {key:'doctor_time',label:'Doctor local time',render:r=>scheduleRange(r.client_shift_starts_at,r.client_shift_ends_at,r.client_timezone||r.client?.timezone)},
    {key:'va_time',label:'Your local time',render:r=>scheduleRange(r.shift_starts_at,r.shift_ends_at,r.employee_timezone||r.timezone)},
    {key:'va_timezone',label:'VA timezone',render:r=>r.employee_timezone||r.timezone||'Not specified'},
    {key:'doctor_timezone',label:'Doctor timezone',render:r=>r.client_timezone||r.client?.timezone||'Not specified'},
    {key:'status',label:'Status',render:r=>{const enabled=enabledValue(r.enabled);return <Badge tone={enabled?'success':'danger'}>{enabled?'Enabled':'Disabled'}</Badge>}},
  ];
  return <><PageHeader eyebrow="Read-only schedule" title="My schedule" description="Doctor / Client local time controls check-in, late and overtime. Your VA-local time is the converted view of the same shift."/><NB n={n}/><Card><div className="filter-grid"><Input label="Search" value={filters.search} onChange={e=>setFilters({...filters,search:e.target.value})} placeholder="Client, day, time or timezone"/><Select label="Doctor day" value={filters.day} onChange={e=>setFilters({...filters,day:e.target.value})}><option value="">All days</option>{['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map(day=><option key={day} value={day}>{day}</option>)}</Select><Select label="Status" value={filters.status} onChange={e=>setFilters({...filters,status:e.target.value})}><option value="">All statuses</option><option value="enabled">Enabled</option><option value="disabled">Disabled</option></Select><Select label="Doctor timezone" value={filters.timezone} onChange={e=>setFilters({...filters,timezone:e.target.value})}><option value="">All timezones</option>{timezoneOptions.map(zone=><option key={zone} value={zone}>{zone}</option>)}</Select><div className="filter-actions"><Button variant="ghost" onClick={reset}>Reset</Button></div></div><Table columns={columns} rows={filteredRows} emptyTitle="No schedule matches these filters"/></Card></>;
}

export function EmployeeAttendance(){
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[filters,setFilters]=useState({from:'',to:'',search:''}),[page,setPage]=useState(1),[busy,setBusy]=useState(false),[modal,setModal]=useState(false),[selected,setSelected]=useState(null),[form,setForm]=useState({clock_in:'',clock_out:'',reason:''}),[requests,setRequests]=useState([]);const n=useNotice();
 const load=async(nextFilters=filters,nextPage=page)=>{setBusy(true);try{const [r,c]=await Promise.all([api.get('/employee/attendance',{params:{...nextFilters,page:nextPage}}),api.get('/employee/change-requests')]);setRows(r.data.data);setMeta(r.data);setRequests(c.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load(filters,page)},[page]);const apply=()=>{setPage(1);load(filters,1)};const reset=()=>{const clean={from:'',to:'',search:''};setFilters(clean);setPage(1);load(clean,1)};const open=r=>{setSelected(r);const sourceTz=r.client_timezone||r.client?.timezone||r.timezone_snapshot||'UTC';const inTime=timeValue(r.clock_in,sourceTz);const outTime=timeValue(r.clock_out,sourceTz);setForm({clock_in:inTime,clock_out:outTime,reason:''});setModal(true)};const submit=async()=>{setBusy(true);try{await api.post('/employee/change-requests',{attendance_id:selected.id,...form});n.success('Correction request submitted.');setModal(false);load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 const columns=[{key:'date',label:'Date',render:r=><DualDate value={r.clock_in||r.date} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} vaTz={r.employee_timezone||'UTC'}/>},{key:'client',label:'Client',render:r=>r.client?.name||'Legacy / unallocated'},{key:'clock_in',label:'Clock in',render:r=><DualTime value={r.clock_in} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} vaTz={r.employee_timezone||'UTC'}/>},{key:'clock_out',label:'Clock out',render:r=><DualTime value={r.clock_out} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} vaTz={r.employee_timezone||'UTC'} live={!r.clock_out}/>},{key:'break',label:'Break',render:r=>`${Math.floor(Number(r.break_taken||0)/60)} min`},{key:'worked',label:'Worked',render:r=>mins(r.total_minutes)},{key:'overtime',label:'Overtime',render:r=>`${r.overtime||0} min`},{key:'action',label:'Action',render:r=>r.clock_out?<Button variant="ghost" onClick={()=>open(r)}>Request change</Button>:'—'}];
 return <><PageHeader eyebrow="My time records" title="Attendance" description="Doctor time is the calculation source; your VA time is shown separately as the local conversion of the same attendance instant."/><NB n={n}/><Card><div className="filter-grid"><Input label="From date" type="date" value={filters.from} onChange={e=>setFilters({...filters,from:e.target.value})}/><Input label="To date" type="date" value={filters.to} onChange={e=>setFilters({...filters,to:e.target.value})}/><Input label="Search" value={filters.search} onChange={e=>setFilters({...filters,search:e.target.value})} placeholder="Date or clock time"/><div className="filter-actions"><Button onClick={apply}>Filter</Button><Button variant="ghost" onClick={reset}>Reset</Button></div></div><Table columns={columns} rows={rows} emptyTitle="No attendance found"/><Pagination meta={meta} onPage={setPage}/></Card><Card title="My change requests" subtitle="Only your own correction requests are shown."><Table columns={[{key:'date',label:'Date',render:r=>String(r.date||'').slice(0,10)},{key:'requested',label:'Requested',render:r=>`${r.requested_clock_in} → ${r.requested_clock_out}`},{key:'reason',label:'Reason'},{key:'status',label:'Status',render:r=><Badge tone={statusTone(r.status)}>{r.status}</Badge>},{key:'created',label:'Submitted',render:r=>dateTime(r.created_at)}]} rows={requests} emptyTitle="No correction requests"/></Card><Modal open={modal} onClose={()=>setModal(false)} title="Request attendance correction" footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={submit}>Submit request</Button></>}><div className="form-grid one-col"><Input label="Date" value={String(selected?.date||'').slice(0,10)} disabled/><Input label="Clock in *" type="time" value={form.clock_in} onChange={e=>setForm({...form,clock_in:e.target.value})}/><Input label="Clock out *" type="time" value={form.clock_out} onChange={e=>setForm({...form,clock_out:e.target.value})}/><Textarea label="Reason *" value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})} placeholder="Explain why the recorded time needs correction"/></div></Modal></>;
}

export function EmployeeProfile(){
 const { refresh: refreshAuth }=useAuth();
 const [employee,setEmployee]=useState(null),[form,setForm]=useState({}),[photo,setPhoto]=useState(null),[documents,setDocuments]=useState([]),[busy,setBusy]=useState(false);const n=useNotice();
 const load=async()=>{try{const r=await api.get('/employee/profile');setEmployee(r.data);setForm(r.data)}catch(e){n.error(messageFrom(e))}};
 useEffect(()=>{load()},[]);
 if(!employee)return <Loading/>;
 const requiredFields=['first_name','last_name','email','phone','date_of_birth','gender','marital_status'];
 const missing=Array.isArray(employee.profile_missing_fields)?employee.profile_missing_fields:requiredFields.filter(key=>!String(employee?.[key]??'').trim());
 const progress=Number.isFinite(Number(employee.profile_completion_percent))?Number(employee.profile_completion_percent):Math.round(((requiredFields.length-missing.length)/requiredFields.length)*100);
 const save=async()=>{setBusy(true);try{const fd=new FormData();['first_name','last_name','email','phone','date_of_birth','gender','marital_status','about_me_notes'].forEach(k=>{if(form[k]!=null)fd.append(k,form[k])});if(photo)fd.append('profile_picture',photo);documents.forEach(f=>fd.append('documents[]',f));const {data}=await api.post('/employee/profile',fd,{headers:{'Content-Type':'multipart/form-data'}});n.success(data.message);setPhoto(null);setDocuments([]);await load();await refreshAuth()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 const removeDoc=async path=>{try{await api.delete('/employee/profile/document',{data:{path}});n.success('Document removed.');load()}catch(e){n.error(messageFrom(e))}};
 const docs=Array.isArray(employee.upload_documents)?employee.upload_documents:[];
 return <>
  <PageHeader eyebrow={employee.profile_completed?'Self service':'First login setup'} title="My profile" description={employee.profile_completed?'Update your personal details, profile photo and documents.':'Complete all required fields to unlock Dashboard, Schedule, Attendance, Notifications and Settings.'} actions={<Button loading={busy} icon={Save} onClick={save}>{employee.profile_completed?'Save changes':'Complete profile'}</Button>}/>
  <NB n={n}/>
  {!employee.profile_completed&&<div className="profile-completion-card">
    <div className="profile-completion-head"><strong>Profile completion</strong><span>{Math.max(0,Math.min(100,progress))}%</span></div>
    <div className="profile-completion-track"><i style={{width:`${Math.max(0,Math.min(100,progress))}%`}}/></div>
    <div className="profile-required-note">Required before access unlocks: First name, Last name, Email, Phone, Date of birth, Gender and Marital status.</div>
  </div>}
  <div className="two-col profile-layout">
   <Card title="Basic information" subtitle={!employee.profile_completed?'Fields marked * are required to unlock your workspace.':'Keep your contact and personal information up to date.'}>
    <div className="profile-photo-row"><div className="profile-photo"><AvatarMedia src={employee.profile_picture} alt={`${employee.first_name || ''} ${employee.last_name || ''}`.trim()} fallback={`${employee.first_name?.[0] || ''}${employee.last_name?.[0] || ''}`}/></div><label className="upload-button"><Upload size={16}/>Choose photo<input type="file" accept="image/jpeg,image/png" onChange={e=>setPhoto(e.target.files?.[0]||null)}/></label><small>JPG/JPEG/PNG · max 800 KB</small></div>
    <div className="form-grid">
     <Input label="First name *" value={form.first_name||''} onChange={e=>setForm({...form,first_name:e.target.value})}/>
     <Input label="Last name *" value={form.last_name||''} onChange={e=>setForm({...form,last_name:e.target.value})}/>
     <Input label="Email *" type="email" value={form.email||''} onChange={e=>setForm({...form,email:e.target.value})}/>
     <Input label="Phone *" value={form.phone||''} onChange={e=>setForm({...form,phone:e.target.value})}/>
     <Input label="Date of birth *" type="date" value={String(form.date_of_birth||'').slice(0,10)} onChange={e=>setForm({...form,date_of_birth:e.target.value})}/>
     <Select label="Gender *" value={form.gender||''} onChange={e=>setForm({...form,gender:e.target.value})}><option value="">Select gender</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></Select>
     <Input label="Marital status *" value={form.marital_status||''} onChange={e=>setForm({...form,marital_status:e.target.value})} placeholder="Single, Married, etc."/>
     <Textarea label="About me" value={form.about_me_notes||''} onChange={e=>setForm({...form,about_me_notes:e.target.value})}/>
    </div>
   </Card>
   <div className="stack">
    <Card title="Work profile" subtitle="Administrative fields are read-only in self service."><div className="detail-list"><div><span>Employee #</span><strong>{employee.employee_code}</strong></div><div><span>Department</span><strong>{employee.department?.name||'—'}</strong></div><div><span>Designation</span><strong>{employee.designation?.name||'—'}</strong></div><div><span>Status</span><strong>{employee.employee_status?.name||'—'}</strong></div><div><span>Timezone</span><strong>{employee.timezone||'Not specified'}</strong></div></div></Card>
    <Card title="Documents" subtitle="Optional: PDF/DOC/DOCX/JPG/PNG files up to 5 MB."><label className="drop-upload"><Upload/><strong>Add documents</strong><span>{documents.length?`${documents.length} file(s) selected`:'Choose one or more files'}</span><input type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={e=>setDocuments(Array.from(e.target.files||[]))}/></label><div className="document-list">{docs.map((d,i)=><div key={d}><a href={d} target="_blank" rel="noreferrer">Document {i+1}</a><button onClick={()=>removeDoc(d)}><Trash2 size={15}/></button></div>)}{!docs.length&&<span className="muted">No documents uploaded.</span>}</div></Card>
   </div>
  </div>
 </>;
}

export function EmployeeSettings(){const [form,setForm]=useState({current_password:'',password:'',password_confirmation:''}),[busy,setBusy]=useState(false);const n=useNotice();const save=async()=>{setBusy(true);try{const {data}=await api.put('/employee/password',form);n.success(data.message);setForm({current_password:'',password:'',password_confirmation:''})}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};return <><PageHeader eyebrow="Security" title="Settings" description="Change your password or connect your organization Google account."/><NB n={n}/><div className="two-col"><Card title="Change password" subtitle="Current password confirmation is required."><div className="form-grid one-col"><Input label="Current password" type="password" value={form.current_password} onChange={e=>setForm({...form,current_password:e.target.value})}/><Input label="New password" type="password" value={form.password} onChange={e=>setForm({...form,password:e.target.value})}/><Input label="Confirm new password" type="password" value={form.password_confirmation} onChange={e=>setForm({...form,password_confirmation:e.target.value})}/><Button loading={busy} icon={KeyRound} onClick={save}>Change password</Button></div></Card><Card title="Google connection" subtitle="Google OAuth opens the provider consent flow and returns to CareVMA."><div className="connection-card"><div className="connection-icon"><Link2/></div><div><strong>Google Workspace</strong><span>Connect your approved organization account.</span></div><a className="btn btn-secondary btn-link" href="/google/connect">Connect Google</a></div></Card></div></>}
