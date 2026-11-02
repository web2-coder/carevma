import { useEffect, useState } from 'react';
import '../../css/live-session-timer.css';
import '../../css/dual-timezone-shift.css';
import '../../css/dual-timezone-tables.css';
import { Coffee, LogIn, Pause, Power, TimerReset, Upload, Save, KeyRound, Link2, CalendarDays, Clock3, Zap, RefreshCw } from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { formatDate, formatDateTime, formatTime, formatWeekday, zonedNowParts } from '../timezone';
import { PageHeader, Card, Button, Input, Select, MaritalStatusSelect, Textarea, Badge, statusTone, useNotice, Table, Pagination, Loading, Modal, mins, durationHM, dateTime, timeValue, AvatarMedia, queryParam } from '../components/ui';


function tzDate(value,timeZone){return formatDate(value,timeZone||'UTC')}

function DualTime({value,doctorTz,live=false}){
  if(!value) return live?<Badge tone="warning">Live</Badge>:<span>—</span>;
  return <div className="dual-time-cell">
    <div className="dual-time-row doctor-time"><strong>Doctor</strong><span>{dateTime(value,doctorTz||'UTC')}</span><small>{doctorTz||'UTC'}</small></div>
  </div>;
}
function DualDate({value,doctorTz}){
  return <div className="dual-date-cell"><span><strong>Doctor</strong> {tzDate(value,doctorTz||'UTC')}</span></div>;
}
function breakCodeText(value){
  const raw=Number(value||0).toFixed(2);
  const [hoursPart='0',minutesPart='00']=raw.split('.');
  const hours=Math.max(0,Number(hoursPart)||0);
  const minutes=Math.min(59,Math.max(0,Number(minutesPart)||0));
  if(!hours&&!minutes)return 'No break';
  return `${hours?`${hours} hr${hours===1?'':'s'} `:''}${minutes?`${minutes} min`:''}`.trim();
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
function DoctorSegmentTimer({segment,serverNow}){
  const initial=Math.max(0,Number(segment?.elapsed_seconds||0));
  const [elapsed,setElapsed]=useState(initial);

  useEffect(()=>{
    if(!segment||segment.status==='upcoming'){setElapsed(0);return undefined}
    let base=Math.max(0,Number(segment.elapsed_seconds||0));
    if(base===0&&segment.clock_in_utc){
      const nowMs=Date.parse(serverNow||'');
      const startMs=Date.parse(segment.clock_in_utc||'');
      if(Number.isFinite(nowMs)&&Number.isFinite(startMs)&&nowMs>=startMs)base=Math.floor((nowMs-startMs)/1000);
    }
    setElapsed(base);
    if(segment.status!=='active')return undefined;
    const anchor=performance.now();
    const tick=()=>setElapsed(base+Math.max(0,Math.floor((performance.now()-anchor)/1000)));
    tick();
    const id=setInterval(tick,1000);
    return()=>clearInterval(id);
  },[segment?.attendance_id,segment?.status,segment?.clock_in_utc,segment?.elapsed_seconds,serverNow]);

  return <strong className="doctor-segment-timer-value">{durationText(elapsed)}</strong>;
}


function DoctorCurrentTime({serverNow,timezone}){
  const parsed=Date.parse(serverNow||'');
  const [nowMs,setNowMs]=useState(()=>Number.isFinite(parsed)?parsed:Date.now());
  useEffect(()=>{
    const start=Date.parse(serverNow||'');
    const anchor=Number.isFinite(start)?start:Date.now();
    const perf=performance.now();
    const tick=()=>setNowMs(anchor+Math.max(0,performance.now()-perf));
    tick();
    const id=setInterval(tick,1000);
    return()=>clearInterval(id);
  },[serverNow,timezone]);
  const date=new Date(nowMs);
  let time='—',day='—';
  try{
    time=new Intl.DateTimeFormat('en-US',{timeZone:timezone||'UTC',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true}).format(date);
    day=new Intl.DateTimeFormat('en-US',{timeZone:timezone||'UTC',weekday:'short',month:'short',day:'numeric'}).format(date);
  }catch{}
  return <div className="doctor-current-time-block">
    <strong>{time}</strong>
    <span>{day}</span>
    <small>{timezone||'UTC'}</small>
  </div>;
}

function ShiftEndStatus({end,serverNow,timeZone}){
  const parsed=Date.parse(serverNow||'');
  const [nowMs,setNowMs]=useState(()=>Number.isFinite(parsed)?parsed:Date.now());
  useEffect(()=>{
    const start=Date.parse(serverNow||'');
    const anchorMs=Number.isFinite(start)?start:Date.now();
    const perf=performance.now();
    const tick=()=>setNowMs(anchorMs+Math.max(0,performance.now()-perf));
    tick();const id=setInterval(tick,1000);return()=>clearInterval(id);
  },[serverNow,end]);
  const endMs=Date.parse(end||'');
  if(!Number.isFinite(endMs))return <small>Shift end not available</small>;
  const left=Math.max(0,Math.floor((endMs-nowMs)/1000));
  const hours=Math.floor(left/3600),minutes=Math.floor((left%3600)/60),seconds=left%60;
  const remaining=endMs<=nowMs?'Shift end reached':`Ends in ${hours?`${hours}h `:''}${minutes}m ${seconds}s`;
  return <div className="shift-end-status"><strong>Ends at {formatZoneTime(end,timeZone||'UTC')}</strong><small>{remaining} · {timeZone||'UTC'}</small></div>;
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

const MONO_HMS=s=>{s=Math.max(0,Math.floor(Number(s)||0));return {h:Math.floor(s/3600),m:Math.floor((s%3600)/60),s:s%60}};
const hmText=(secs,withSeconds=false)=>{const {h,m,s}=MONO_HMS(secs);return `${h}h ${String(m).padStart(2,'0')}m${withSeconds?` ${String(s).padStart(2,'0')}s`:''}`};

function LiveSessionCounter({active, serverNow, activeBreak, employeeTimezone}){
  // One ticking offset (seconds since this payload) drives every figure, so a
  // refresh or a 30s reload never restarts anything: the backend measures the
  // bases from the original clock-in in UTC.
  const [offset,setOffset]=useState(0);
  useEffect(()=>{
    const anchor=performance.now();
    const tick=()=>setOffset(Math.max(0,Math.floor((performance.now()-anchor)/1000)));
    tick();const id=setInterval(tick,1000);return()=>clearInterval(id);
  },[active?.id,serverNow]);

  const serverMs=Date.parse(serverNow||'');
  const nowMs=(Number.isFinite(serverMs)?serverMs:Date.now())+offset*1000;
  let base=Math.max(0,Number(active?.session_elapsed_seconds??active?.elapsed_seconds??0));
  if(base===0){const clockInMs=Date.parse(active?.clock_in_utc||active?.clock_in||'');if(Number.isFinite(serverMs)&&Number.isFinite(clockInMs)&&serverMs>=clockInMs)base=Math.floor((serverMs-clockInMs)/1000)}
  const elapsed=base+offset;
  const segment=(activeBreak?Math.max(0,Number(active?.current_break_elapsed_seconds||0)):Math.max(0,Number(active?.segment_elapsed_seconds??base)))+offset;
  const startMs=Date.parse(active?.shift_start_snapshot||''),endMs=Date.parse(active?.shift_end_utc||active?.shift_end_snapshot||'');
  const total=Number.isFinite(startMs)&&Number.isFinite(endMs)&&endMs>startMs?Math.floor((endMs-startMs)/1000):0;
  const left=Number.isFinite(endMs)?Math.max(0,Math.floor((endMs-nowMs)/1000)):null;
  const pct=total?Math.min(100,Math.round(elapsed/total*100)):0;
  const doctorTz=active?.doctor_timezone||active?.client?.timezone||'UTC';
  const {h:hours,m:minutes,s:seconds}=MONO_HMS(segment);
  const pad=value=>String(value).padStart(2,'0');
  const R=54,C=2*Math.PI*R;

  return <div className={`live-session-counter${activeBreak?' is-break':''}`}>
    <div className="session-hub">
      <div className="session-ring" role="img" aria-label={`${pct}% of the shift completed, ${durationText(elapsed)} elapsed`}>
        <svg viewBox="0 0 120 120" aria-hidden="true">
          <defs><linearGradient id="session-ring-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" style={{stopColor:'var(--primary)'}}/><stop offset="100%" style={{stopColor:'color-mix(in srgb, var(--primary) 45%, #fff)'}}/></linearGradient></defs>
          <circle className="ring-track" cx="60" cy="60" r={R}/>
          <circle className="ring-bar" cx="60" cy="60" r={R} strokeDasharray={C} strokeDashoffset={C*(1-pct/100)}/>
        </svg>
        <div className="session-ring-center">
          <span className="ring-pct">{pct}% completed</span>
          <strong>{durationText(elapsed)}</strong>
          <span className="ring-label">Elapsed time</span>
          {left!==null&&<em><i/>{left?`${hmText(left)} left`:'Shift end reached'}</em>}
        </div>
      </div>
      <div className="session-counter">
        <span className="eyebrow">{activeBreak?'Current break counter':'Current work session counter'}</span>
        <h3>{activeBreak?'Break Elapsed':'Active Segment Elapsed'}</h3>
        <div className="live-counter-digits" aria-label={`${hours} hours ${minutes} minutes ${seconds} seconds`}>
          <div className="live-counter-segment"><strong>{pad(hours)}</strong><span>Hours</span></div>
          <b>:</b>
          <div className="live-counter-segment"><strong>{pad(minutes)}</strong><span>Minutes</span></div>
          <b>:</b>
          <div className="live-counter-segment"><strong>{pad(seconds)}</strong><span>Seconds</span></div>
        </div>
        <div className="session-end-tiles">
          <div><span>Scheduled end</span><strong>{Number.isFinite(endMs)?<>{formatZoneTime(active.shift_end_utc||active.shift_end_snapshot,doctorTz)} (Doctor) · {formatZoneTime(active.shift_end_utc||active.shift_end_snapshot,employeeTimezone)} (You)</>:'—'}</strong></div>
          <div><span>Remaining duration</span><strong className="is-left">{left===null?'—':hmText(left,true)}</strong></div>
        </div>
      </div>
    </div>
    <p className="session-hub-note"><RefreshCw size={13} aria-hidden="true"/>{activeBreak?'Break is active. The ring keeps counting from your original clock-in.':'Radial ring and counter continue from your original clock-in and survive page refreshes.'}</p>
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
function DualTimezoneClock({serverNow,timezone,label='Current time'}){
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
    <div><span>{label}</span><strong>{timezone?formatZone(iso,timezone,{seconds:true}):'—'}</strong><small>{timezone||'Timezone not set'}</small></div>
  </div>;
}
function scheduleRange(start,end,timeZone){
  if(!start||!end||!timeZone)return '—';
  return `${formatZone(start,timeZone)} – ${formatZoneTime(end,timeZone)}`;
}

const tzLongName=tz=>{try{return (new Intl.DateTimeFormat('en-US',{timeZone:tz,timeZoneName:'long'}).formatToParts(new Date()).find(p=>p.type==='timeZoneName')?.value||'').replace(/ (Standard|Daylight) /,' ')}catch{return ''}};

export function EmployeeDashboard(){
  const [data,setData]=useState(null),[busy,setBusy]=useState(false),[checkInTarget,setCheckInTarget]=useState(null),[checkInComment,setCheckInComment]=useState('');const n=useNotice();
  const load=async({silent=false}={})=>{if(!silent)setBusy(true);try{const {data:response}=await api.get('/employee/dashboard');setData({...response,today_schedules:Array.isArray(response.today_schedules)?response.today_schedules:[]})}catch(e){if(!silent)n.error(messageFrom(e))}finally{if(!silent)setBusy(false)}};
  useEffect(()=>{load();const timer=setInterval(load,30000);const onRefresh=()=>load();const onVisibility=()=>{if(document.visibilityState==='visible')load()};window.addEventListener('focus',onRefresh);window.addEventListener('carevma:timezone-updated',onRefresh);document.addEventListener('visibilitychange',onVisibility);return()=>{clearInterval(timer);window.removeEventListener('focus',onRefresh);window.removeEventListener('carevma:timezone-updated',onRefresh);document.removeEventListener('visibilitychange',onVisibility)}},[]);
  const action=async(fn,payload={})=>{setBusy(true);try{const {data:r}=await api.post(fn,payload);n.success(r.message);await load();return true}catch(e){n.error(messageFrom(e));return false}finally{setBusy(false)}};
  const submitCheckIn=async()=>{if(!checkInTarget)return;const ok=await action('/employee/clock-in',{schedule_id:checkInTarget.id,comment:checkInComment.trim()||null});if(ok){setCheckInTarget(null);setCheckInComment('')}};
  if(!data)return <Loading/>;
  const active=data.active_attendance;const activeBreak=active?.breaks?.find(b=>!b.end_time);const activeClientTimezone=active?.client?.timezone||null;const employeeTimezone=data.timezone||data.employee?.timezone||'UTC';const doctorSegments=Array.isArray(active?.active_doctors)?active.active_doctors:[];const activeDoctors=doctorSegments.filter(x=>x.status==='active');const doctorNames=(activeDoctors.length?activeDoctors:doctorSegments).map(x=>x.client_name).filter(Boolean);const shiftTotal=active?Math.max(0,Math.floor((Date.parse(active.shift_end_utc||active.shift_end_snapshot||'')-Date.parse(active.shift_start_snapshot||''))/1000))||0:0;
  return <>
    <PageHeader eyebrow="My workday" title={`Welcome, ${data.employee.first_name}`} description="One VA check-in controls your work session. Each assigned Doctor shift is calculated independently in that Doctor's timezone."/>
    <div className="employee-dashboard-pro">
      <Card className="shift-control-card" title={active?<span className={`session-state-pill${activeBreak?' is-break':''}`}><i/>{activeBreak?'Break in Progress':'Shift Session in Progress'}</span>:'Shift control'} subtitle={active?null:'Check-in appears 15 minutes before the Doctor-local shift start'} actions={active?<div className="session-hub-actions">{shiftTotal>0&&<span className="shift-total-chip"><Clock3 size={13} aria-hidden="true"/>Shift: {hmText(shiftTotal)} Total</span>}<span className="hub-label">Live session hub</span></div>:null}>
          {active?<div className="shift-control">
            <LiveSessionCounter active={active} serverNow={data.now_utc||data.now} activeBreak={activeBreak} employeeTimezone={employeeTimezone}/>
            <div className="break-usage-wrap">
            <BreakUsagePanel active={active} activeBreak={activeBreak} serverNow={data.now_utc||data.now}/>
            <div className="shift-actions">{activeBreak?<Button variant="warning" loading={busy} icon={Coffee} onClick={()=>action('/employee/break/end')}>End break</Button>:<Button variant="secondary" loading={busy} icon={Pause} onClick={()=>action('/employee/break/start',{notes:'Employee break'})}>Start break</Button>}<Button variant="danger" loading={busy} icon={Power} onClick={()=>action('/employee/clock-out')}>Clock out</Button></div>
            </div>
            <div className="session-stats"><div><span>Break records</span><strong>{active.breaks?.length||0}</strong></div><div className={Number(active.late_minutes||0)>0?'is-late':''}><span>Late</span><strong>{durationHM(active.late_minutes)}</strong></div><div><span>Working with</span><strong>{doctorNames.length?`${doctorNames.length} Doctor${doctorNames.length===1?'':'s'}`:(active.client?.name||'—')}</strong></div></div>
          </div>:<div className="schedule-picker">{data.today_schedules.length?data.today_schedules.map(s=>{const doctorTz=s.client_timezone||s.client?.timezone;return <div className="schedule-choice-card" key={s.id}><div className="schedule-choice-copy"><span>{s.client?.name||'Client'}</span><strong>{scheduleRange(s.client_shift_starts_at,s.client_shift_ends_at,doctorTz)}</strong><small>Doctor time · {doctorTz||'Not set'}</small><small>My local shift: {scheduleRange(s.shift_starts_at,s.shift_ends_at,s.employee_timezone||data.timezone)} · {s.employee_timezone||data.timezone}</small><small>Doctor break: {breakCodeText(s.break_allowed_hours)}</small><small>Check-in opens: {formatZone(s.doctor_check_in_opens_at||s.client_shift_starts_at,doctorTz)}</small></div><Button loading={busy} icon={LogIn} onClick={()=>{setCheckInTarget(s);setCheckInComment('')}}>Check in</Button></div>}):<div className="empty-compact"><strong>No shift open right now</strong><span>Your next check-in option appears 15 minutes before the Doctor-local start time.</span></div>}</div>}
      </Card>
      {active&&doctorSegments.length>0&&<Card className="doctor-shifts-card" title={`Doctor shifts in this session (${doctorSegments.length})`} subtitle="Later assigned Doctor shifts join automatically; completed Doctor segments stop independently." actions={<span className="sync-note">Synchronized realtime</span>}><div className="agenda">{doctorSegments.map(segment=><div className="agenda-row va-agenda-row" key={`${segment.schedule_id}-${segment.client_id}-${segment.status}`}><div className="agenda-time">{segment.shift_starts_at?formatZoneTime(segment.shift_starts_at,segment.doctor_timezone):'—'}</div><div className="va-agenda-main"><div className="va-agenda-title"><strong>{segment.client_name||'Doctor'}</strong><Badge tone={segment.status==='active'?'success':segment.status==='upcoming'?'warning':'neutral'}>{segment.status||'Active'}</Badge></div><span>Doctor: {scheduleRange(segment.shift_starts_at,segment.shift_ends_at,segment.doctor_timezone)} · {segment.doctor_timezone||'UTC'}</span><span>Your time: {scheduleRange(segment.shift_starts_at,segment.shift_ends_at,employeeTimezone)} · {employeeTimezone}</span></div><div className="va-agenda-side">{segment.status==='active'&&<ShiftEndStatus end={segment.shift_ends_at} serverNow={data.now_utc||data.now} timeZone={segment.doctor_timezone}/>}{Number(segment.late_minutes||0)>0&&<span className="late-chip">Late: {durationHM(segment.late_minutes)}</span>}</div></div>)}</div></Card>}
      {active&&<section className="doctor-live-session-section" aria-label="Live VA work session">
            <div className="doctor-live-session-heading">
              <div><span>Live VA work session</span><strong>{doctorNames.length?`${doctorNames.length} active Doctor${doctorNames.length===1?'':'s'}`:(active.client?.name||'Assigned Doctor')}</strong><small>Your main work-session timer stays above. Each Doctor timer starts from that Doctor segment's actual start.</small></div>
            </div>
            <div className="doctor-live-card-grid">
              {(activeDoctors.length?activeDoctors:doctorSegments.filter(x=>x.status==='active')).map(segment=>{
                const doctorTz=segment.doctor_timezone||'UTC';
                return <article className="doctor-live-card" key={`live-${segment.attendance_id||segment.schedule_id||segment.client_id}`}>
                  <div className="doctor-live-card-head"><div><span>Active Doctor</span><div className="doctor-live-card-name"><strong>{segment.client_name||'Doctor'}</strong><Badge tone="success">Active</Badge></div><small>Clinic timezone: <b>{doctorTz}{tzLongName(doctorTz)?` (${tzLongName(doctorTz)})`:''}</b></small></div><span className="doctor-live-avatar" aria-hidden="true">{String(segment.client_name||'Dr').split(/\s+/).filter(w=>w&&!/^dr\.?$/i.test(w)).slice(0,2).map(w=>w[0]).join('').toUpperCase()||'DR'}</span></div>
                  <div className="doctor-live-card-grid-inner">
                    <div className="doctor-current-time-cell"><span>Doctor current time</span><DoctorCurrentTime serverNow={data.now_utc||data.now} timezone={doctorTz}/></div>
                    <div className="is-timer"><span>Doctor session timer</span><DoctorSegmentTimer segment={segment} serverNow={data.now_utc||data.now}/><small>Starts from this Doctor segment</small></div>
                    <div><span>Doctor shift</span><strong>{scheduleRange(segment.shift_starts_at,segment.shift_ends_at,doctorTz)}</strong><small>{doctorTz}</small></div>
                    <div><span>Your local equivalent</span><strong>{scheduleRange(segment.shift_starts_at,segment.shift_ends_at,employeeTimezone)}</strong><small>{employeeTimezone}</small></div>
                    <div><span>Shift end</span><ShiftEndStatus end={segment.shift_ends_at} serverNow={data.now_utc||data.now} timeZone={doctorTz}/></div>
                    <div className={Number(segment.late_minutes||0)>0?'is-late':''}><span>Late</span><strong>{durationHM(segment.late_minutes)}</strong><small>Calculated for this Doctor only</small></div>
                  </div>
                </article>;
              })}
            </div>
          </section>}
      <div className="shift-side-cards">
          <div className="shift-side-card"><span className="shift-side-icon"><Clock3 size={19}/></span><div><span>Your timezone</span><strong>{employeeTimezone}</strong><small>Doctor timezones are shown separately on each shift card</small></div></div>
          <div className={`shift-side-card ${data.today_schedules.length?'is-ready':''}`}><span className="shift-side-icon"><CalendarDays size={19}/></span><div><span>Available shifts</span><strong>{data.today_schedules.length}</strong><small>Doctor-local 15-minute window</small></div></div>
          <div className={`shift-side-card ${active?'is-live':''}`}><span className="shift-side-icon"><Zap size={19}/></span><div><span>Session</span><strong>{active?'Live':data.today_schedules.length?'Ready':data.completed_today?'Completed':'Not started'}</strong><small>{active?'One VA session is running':data.today_schedules.length?'A shift is ready':'Current work state'}</small></div></div>
      </div>
      {!active&&data.today_schedules.length===0&&Array.isArray(data.next_schedules)&&data.next_schedules.length>0&&<Card className="available-now-card" title="Next shift" subtitle="Check-in eligibility and displayed times use the Doctor / Client timezone."><div className="agenda">{data.next_schedules.slice(0,1).map(s=>{const doctorTz=s.client_timezone||s.client?.timezone;const vaTz=s.employee_timezone||data.timezone;return <div className="agenda-row" key={`next-${s.id}`}><div className="agenda-time">{formatZoneTime(s.client_shift_starts_at,doctorTz)}</div><div><strong>{s.client?.name||'Client'}</strong><span>Doctor shift: {scheduleRange(s.client_shift_starts_at,s.client_shift_ends_at,doctorTz)} · {doctorTz}</span><span>My local shift: {scheduleRange(s.shift_starts_at,s.shift_ends_at,vaTz)} · {vaTz}</span><span>Doctor break: {breakCodeText(s.break_allowed_hours)}</span></div><Badge tone="warning">Upcoming</Badge></div>})}</div></Card>}
    </div>
    <Modal open={!!checkInTarget} onClose={()=>setCheckInTarget(null)} title="Check in" footer={<><Button variant="ghost" onClick={()=>setCheckInTarget(null)}>Cancel</Button><Button loading={busy} icon={LogIn} onClick={submitCheckIn}>Start work session</Button></>}><div className="form-grid one-col"><Input label="Doctor / Client" value={checkInTarget?.client?.name||'Doctor'} disabled/><Input label="Doctor shift" value={checkInTarget?scheduleRange(checkInTarget.client_shift_starts_at,checkInTarget.client_shift_ends_at,checkInTarget.client_timezone||checkInTarget.client?.timezone):''} disabled/><Textarea label="Early / late check-in comment (optional)" value={checkInComment} onChange={e=>setCheckInComment(e.target.value)} placeholder="Add a short reason if you are checking in early or late"/></div></Modal>
  </>;
}
export function EmployeeSchedule(){
  const [rows,setRows]=useState(null),[requests,setRequests]=useState([]),[filters,setFilters]=useState({search:queryParam('search'),day:'',status:'',timezone:''}),[requestOpen,setRequestOpen]=useState(false),[selected,setSelected]=useState(null),[busy,setBusy]=useState(false),[form,setForm]=useState({requested_date:'',requested_start_time:'',requested_end_time:'',reason:''});const n=useNotice();
  const load=async()=>{setBusy(true);try{const [scheduleResponse,requestResponse]=await Promise.all([api.get('/employee/schedules'),api.get('/employee/schedule-change-requests')]);setRows(Array.isArray(scheduleResponse.data)?scheduleResponse.data:[]);setRequests(Array.isArray(requestResponse.data)?requestResponse.data:[])}catch(e){n.error(messageFrom(e));setRows([])}finally{setBusy(false)}};
  useEffect(()=>{
    load();
    const onTimezone=()=>load();
    // Keep check-in eligibility in sync with the Doctor-local 15-minute window.
    // Without this poll, a VA who opened the dashboard before the window had to
    // reload the page manually before the Check in action appeared.
    const shiftWindowPoll=window.setInterval(()=>load({silent:true}),15000);
    window.addEventListener('carevma:timezone-updated',onTimezone);
    return()=>{
      window.clearInterval(shiftWindowPoll);
      window.removeEventListener('carevma:timezone-updated',onTimezone);
    };
  },[]);
  if(!rows)return <Loading/>;
  const timezoneOptions=[...new Set(rows.map(r=>r.client_timezone||r.client?.timezone).filter(Boolean))].sort();const search=filters.search.trim().toLowerCase();
  const filteredRows=rows.filter(row=>{const enabled=enabledValue(row.enabled);const matchesSearch=!search||[row.weekday,row.client?.name,row.client?.business_name,row.client_timezone,row.start_time,row.end_time].some(value=>String(value||'').toLowerCase().includes(search));const matchesDay=!filters.day||String(row.weekday||'')===filters.day;const matchesStatus=!filters.status||(filters.status==='enabled'?enabled:!enabled);const matchesTimezone=!filters.timezone||String(row.client_timezone||row.client?.timezone||'')===filters.timezone;return matchesSearch&&matchesDay&&matchesStatus&&matchesTimezone});
  const reset=()=>setFilters({search:'',day:'',status:'',timezone:''});
  const openRequest=r=>{setSelected(r);setForm({requested_date:r.schedule_date?String(r.schedule_date).slice(0,10):'',requested_start_time:String(r.start_time||'').slice(0,5),requested_end_time:String(r.end_time||'').slice(0,5),reason:''});setRequestOpen(true)};
  const submit=async()=>{if(!selected)return;setBusy(true);try{const {data}=await api.post('/employee/schedule-change-requests',{schedule_id:selected.id,...form,requested_date:form.requested_date||null});n.success(data?.message||'Schedule / shift change request submitted.');setRequestOpen(false);await load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
  const columns=[
    {key:'weekday',label:'Doctor day'},
    {key:'client',label:'Client / Doctor',render:r=>r.client?.name||'—'},
    {key:'doctor_time',label:'Doctor local time',render:r=>scheduleRange(r.client_shift_starts_at,r.client_shift_ends_at,r.client_timezone||r.client?.timezone)},
    {key:'va_local_time',label:'My local shift',render:r=>scheduleRange(r.shift_starts_at,r.shift_ends_at,r.employee_timezone||'UTC')},
    {key:'doctor_timezone',label:'Doctor timezone',render:r=>r.client_timezone||r.client?.timezone||'Not specified'},
    {key:'doctor_break',label:'Doctor break',render:r=><Badge tone="info">{breakCodeText(r.break_allowed_hours)}</Badge>},
    {key:'status',label:'Status',render:r=>{const enabled=enabledValue(r.enabled);return <Badge tone={enabled?'success':'danger'}>{enabled?'Enabled':'Disabled'}</Badge>}},
    {key:'action',label:'Change Request',render:r=><Button variant="secondary" onClick={()=>openRequest(r)}>Request change</Button>},
  ];
  const requestColumns=[{key:'doctor',label:'Doctor',render:r=>r.schedule?.client?.name||r.schedule?.client?.business_name||'—'},{key:'date',label:'Requested date',render:r=>r.requested_date?String(r.requested_date).slice(0,10):(r.schedule?.weekday||'Weekly')},{key:'time',label:'Requested time',render:r=>`${String(r.requested_start_time||'').slice(0,5)} → ${String(r.requested_end_time||'').slice(0,5)}`},{key:'reason',label:'Reason'},{key:'status',label:'Status',render:r=><Badge tone={statusTone(r.status)}>{r.status}</Badge>}];
  return <><PageHeader eyebrow="Schedule" title="My schedule" description="Doctor time remains canonical. Use Change Request to ask Admin/HR for a date or shift-time change."/><Card><div className="filter-grid"><Input label="Search" value={filters.search} onChange={e=>setFilters({...filters,search:e.target.value})} placeholder="Client, day, time or timezone"/><Select label="Doctor day" value={filters.day} onChange={e=>setFilters({...filters,day:e.target.value})}><option value="">All days</option>{['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map(day=><option key={day} value={day}>{day}</option>)}</Select><Select label="Status" value={filters.status} onChange={e=>setFilters({...filters,status:e.target.value})}><option value="">All statuses</option><option value="enabled">Enabled</option><option value="disabled">Disabled</option></Select><Select label="Doctor timezone" value={filters.timezone} onChange={e=>setFilters({...filters,timezone:e.target.value})}><option value="">All timezones</option>{timezoneOptions.map(zone=><option key={zone} value={zone}>{zone}</option>)}</Select><div className="filter-actions"><Button variant="ghost" onClick={reset}>Reset</Button></div></div><Table columns={columns} rows={filteredRows} emptyTitle="No schedule matches these filters"/></Card><Card title="My schedule change requests"><Table columns={requestColumns} rows={requests} emptyTitle="No schedule change requests"/></Card><Modal open={requestOpen} onClose={()=>setRequestOpen(false)} title="Schedule / shift change request" footer={<><Button variant="ghost" onClick={()=>setRequestOpen(false)}>Cancel</Button><Button loading={busy} onClick={submit}>Submit request</Button></>}><div className="form-grid one-col"><Input label="Doctor / Client" value={selected?.client?.name||'Doctor'} disabled/><Input label="Requested date (optional for weekly schedule)" type="date" value={form.requested_date} onChange={e=>setForm({...form,requested_date:e.target.value})}/><Input label="Requested start time *" type="time" value={form.requested_start_time} onChange={e=>setForm({...form,requested_start_time:e.target.value})}/><Input label="Requested end time *" type="time" value={form.requested_end_time} onChange={e=>setForm({...form,requested_end_time:e.target.value})}/><Textarea label="Reason *" value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})} placeholder="Explain the requested schedule or shift change"/></div></Modal></>;
}
export function EmployeeAttendance(){
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[filters,setFilters]=useState({from:'',to:'',search:queryParam('search')}),[page,setPage]=useState(1),[busy,setBusy]=useState(false),[modal,setModal]=useState(false),[selected,setSelected]=useState(null),[form,setForm]=useState({clock_in:'',clock_out:'',reason:''}),[requests,setRequests]=useState([]);const n=useNotice();
 const load=async(nextFilters=filters,nextPage=page)=>{setBusy(true);try{const [r,c]=await Promise.all([api.get('/employee/attendance',{params:{...nextFilters,page:nextPage}}),api.get('/employee/change-requests')]);setRows(r.data.data);setMeta(r.data);setRequests(c.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load(filters,page)},[page]);const apply=()=>{setPage(1);load(filters,1)};const reset=()=>{const clean={from:'',to:'',search:''};setFilters(clean);setPage(1);load(clean,1)};const open=r=>{setSelected(r);const sourceTz=r.client_timezone||r.client?.timezone||r.timezone_snapshot||'UTC';const inTime=timeValue(r.clock_in,sourceTz);const outTime=timeValue(r.clock_out,sourceTz);setForm({clock_in:inTime,clock_out:outTime,reason:''});setModal(true)};const submit=async()=>{setBusy(true);try{await api.post('/employee/change-requests',{attendance_id:selected.id,...form});n.success('Correction request submitted.');setModal(false);load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 const requestOvertime=async r=>{setBusy(true);try{const {data}=await api.post(`/employee/overtime/${r.id}/request`);n.success(data?.message||'Overtime review requested.');await load(filters,page)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 const columns=[{key:'date',label:'Date',render:r=><DualDate value={r.clock_in||r.date} doctorTz={r.client_timezone||r.client?.timezone||'UTC'}/>},{key:'client',label:'Doctor Worked With',render:r=>r.client?.name||r.client?.business_name||'Legacy / unallocated'},{key:'clock_in',label:'Clock in',render:r=><DualTime value={r.clock_in} doctorTz={r.client_timezone||r.client?.timezone||'UTC'}/>},{key:'clock_out',label:'Clock out',render:r=><DualTime value={r.clock_out} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} live={!r.clock_out}/>},{key:'break',label:'Break',render:r=>`${Math.floor(Number(r.break_taken||0)/60)} min`},{key:'worked',label:'Regular billed',render:r=>mins(r.total_minutes)},{key:'overtime',label:'Overtime',render:r=><div><strong>{r.overtime||0} min</strong>{Number(r.overtime||0)>0&&<span className="table-subtext">{r.overtime_status==='review_required'?'Review not requested':r.overtime_status==='pending'?'Review pending':r.overtime_status==='approved'?'Approved':'Not approved'}</span>}</div>},{key:'action',label:'Action',render:r=>r.clock_out?<div className="row-actions">{Number(r.overtime||0)>0&&r.overtime_status==='review_required'&&<Button variant="secondary" onClick={()=>requestOvertime(r)}>Request OT review</Button>}<Button variant="ghost" onClick={()=>open(r)}>Request change</Button></div>:'—'}];
 return <><PageHeader eyebrow="My time records" title="Attendance" description="Attendance dates and times are shown in the assigned Doctor / Client timezone."/><Card><div className="filter-grid"><Input label="From date" type="date" value={filters.from} onChange={e=>setFilters({...filters,from:e.target.value})}/><Input label="To date" type="date" value={filters.to} onChange={e=>setFilters({...filters,to:e.target.value})}/><Input label="Search" value={filters.search} onChange={e=>setFilters({...filters,search:e.target.value})} placeholder="Date or clock time"/><div className="filter-actions"><Button onClick={apply}>Filter</Button><Button variant="ghost" onClick={reset}>Reset</Button></div></div><Table columns={columns} rows={rows} emptyTitle="No attendance found"/><Pagination meta={meta} onPage={setPage}/></Card><Card title="My change requests" subtitle="Only your own correction requests are shown."><Table columns={[{key:'date',label:'Date',render:r=>String(r.date||'').slice(0,10)},{key:'requested',label:'Requested',render:r=>`${r.requested_clock_in} → ${r.requested_clock_out}`},{key:'reason',label:'Reason'},{key:'status',label:'Status',render:r=><Badge tone={statusTone(r.status)}>{r.status}</Badge>},{key:'created',label:'Submitted',render:r=>dateTime(r.created_at)}]} rows={requests} emptyTitle="No correction requests"/></Card><Modal open={modal} onClose={()=>setModal(false)} title="Request attendance correction" footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={submit}>Submit request</Button></>}><div className="form-grid one-col"><Input label="Date" value={String(selected?.date||'').slice(0,10)} disabled/><Input label="Clock in *" type="time" value={form.clock_in} onChange={e=>setForm({...form,clock_in:e.target.value})}/><Input label="Clock out *" type="time" value={form.clock_out} onChange={e=>setForm({...form,clock_out:e.target.value})}/><Textarea label="Reason *" value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})} placeholder="Explain why the recorded time needs correction"/></div></Modal></>;
}

export function EmployeeProfile(){
 const { refresh: refreshAuth }=useAuth();
 const [employee,setEmployee]=useState(null),[form,setForm]=useState({}),[photo,setPhoto]=useState(null),[busy,setBusy]=useState(false);const n=useNotice();
 const load=async()=>{try{const r=await api.get('/employee/profile');setEmployee(r.data);setForm(r.data)}catch(e){n.error(messageFrom(e))}};
 useEffect(()=>{load()},[]);
 if(!employee)return <Loading/>;
 const requiredFields=['first_name','last_name','email','phone','date_of_birth','gender','marital_status'];
 const missing=Array.isArray(employee.profile_missing_fields)?employee.profile_missing_fields:requiredFields.filter(key=>!String(employee?.[key]??'').trim());
 const progress=Number.isFinite(Number(employee.profile_completion_percent))?Number(employee.profile_completion_percent):Math.round(((requiredFields.length-missing.length)/requiredFields.length)*100);
 const save=async()=>{setBusy(true);try{const fd=new FormData();['first_name','last_name','phone','date_of_birth','gender','marital_status','about_me_notes'].forEach(k=>{if(form[k]!=null)fd.append(k,form[k])});if(photo)fd.append('profile_picture',photo);const {data}=await api.post('/employee/profile',fd,{headers:{'Content-Type':'multipart/form-data'}});n.success(data.message);setPhoto(null);await load();await refreshAuth()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 return <>
  <PageHeader eyebrow={employee.profile_completed?'Self service':'First login setup'} title="My profile" description={employee.profile_completed?'Update your personal details and profile photo. Documents shared by Admin or HR are available under Shared Documents.':'Complete all required fields to unlock Dashboard, Schedule, Attendance, Notifications and Settings.'} actions={<Button loading={busy} icon={Save} onClick={save}>{employee.profile_completed?'Save changes':'Complete profile'}</Button>}/>
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
     <Input label="Email *" type="email" value={form.email||''} disabled hint="Email is managed by Admin and cannot be changed from the VA profile."/>
     <Input label="Phone *" value={form.phone||''} onChange={e=>setForm({...form,phone:e.target.value})}/>
     <Input label="Date of birth *" type="date" value={String(form.date_of_birth||'').slice(0,10)} onChange={e=>setForm({...form,date_of_birth:e.target.value})}/>
     <Select label="Gender *" value={form.gender||''} onChange={e=>setForm({...form,gender:e.target.value})}><option value="">Select gender</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></Select>
     <MaritalStatusSelect label="Marital status *" value={form.marital_status||''} onChange={e=>setForm({...form,marital_status:e.target.value})}/>
     <Textarea label="About me" value={form.about_me_notes||''} onChange={e=>setForm({...form,about_me_notes:e.target.value})}/>
    </div>
   </Card>
   <div className="stack">
    <Card title="Work & HR profile" subtitle="Administrative fields are read-only in self service and stay synced with Admin."><div className="detail-list"><div><span>Employee #</span><strong>{employee.employee_code||'—'}</strong></div><div><span>Department</span><strong>{employee.department?.name||'—'}</strong></div><div><span>Designation</span><strong>{employee.designation?.name||'—'}</strong></div><div><span>Employment type</span><strong>{employee.employment_type?.name||employee.employmentType?.name||'—'}</strong></div><div><span>Shift type</span><strong>{employee.shift_type?.name||employee.shiftType?.name||'—'}</strong></div><div><span>Status</span><strong>{employee.employee_status?.name||'—'}</strong></div><div><span>Expertise</span><strong>{employee.expertise?.name||'—'}</strong></div><div><span>Reporting manager</span><strong>{employee.reporting_manager?.name||employee.reportingManager?.name||'—'}</strong></div><div><span>Location</span><strong>{employee.location||'—'}</strong></div><div><span>Timezone</span><strong>{employee.timezone||'—'}</strong></div><div><span>Doctor timezone</span><strong>{employee.assigned_doctor_timezone||'Not assigned'}</strong></div><div><span>Joining date</span><strong>{String(employee.date_of_joining||'').slice(0,10)||'—'}</strong></div><div><span>Regularisation date</span><strong>{String(employee.date_of_regularisation||'').slice(0,10)||'—'}</strong></div><div><span>Source of hire</span><strong>{employee.source_of_hire||'—'}</strong></div><div><span>Salary PKR</span><strong>{employee.salary_pkr||'—'}</strong></div><div><span>Salary USD</span><strong>{employee.salary_usd||'—'}</strong></div></div></Card>
    <Card title="Shared Documents" subtitle="VA accounts are read-only for documents. Only files specifically shared by Admin or HR are available."><div className="detail-list"><div><span>Document access</span><strong>View only</strong></div><div><span>Upload permission</span><strong>Admin / HR only</strong></div></div><a className="btn btn-secondary btn-link" href="/employee/documents">Open Shared Documents</a></Card>
   </div>
  </div>
 </>;
}

export function EmployeeSettings(){const [form,setForm]=useState({current_password:'',password:'',password_confirmation:''}),[busy,setBusy]=useState(false);const n=useNotice();const save=async()=>{setBusy(true);try{const {data}=await api.put('/employee/password',form);n.success(data.message);setForm({current_password:'',password:'',password_confirmation:''})}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};return <><PageHeader eyebrow="Security" title="Settings" description="Change your password or connect your organization Google account."/><div className="two-col"><Card title="Change password" subtitle="Current password confirmation is required."><div className="form-grid one-col"><Input label="Current password" type="password" value={form.current_password} onChange={e=>setForm({...form,current_password:e.target.value})}/><Input label="New password" type="password" value={form.password} onChange={e=>setForm({...form,password:e.target.value})}/><Input label="Confirm new password" type="password" value={form.password_confirmation} onChange={e=>setForm({...form,password_confirmation:e.target.value})}/><Button loading={busy} icon={KeyRound} onClick={save}>Change password</Button></div></Card><Card title="Google connection" subtitle="Google OAuth opens the provider consent flow and returns to CareVMA."><div className="connection-card"><div className="connection-icon"><Link2/></div><div><strong>Google Workspace</strong><span>Connect your approved organization account.</span></div><a className="btn btn-secondary btn-link" href="/google/connect">Connect Google</a></div></Card></div></>}
