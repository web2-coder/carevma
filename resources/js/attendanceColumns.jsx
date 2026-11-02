import { AvatarMedia, Badge, durationHM, mins } from './components/ui';

// Attendance rows as the admin, HR and client-profile tables show them: one value per cell,
// context in muted small text, doctor-local times, badges for live / late / overtime.
export const inZone=(value,tz,opts)=>{if(!value)return '';try{return new Intl.DateTimeFormat('en-US',{timeZone:tz||'UTC',...opts}).format(new Date(value))}catch{return ''}};
export const attTime=(value,tz)=>inZone(value,tz,{hour:'numeric',minute:'2-digit',hour12:true});
export const attDay=(value,tz)=>inZone(value,tz,{month:'short',day:'numeric'});
export const attZone=r=>r.client_timezone||r.timezone_snapshot||r.client?.timezone||'UTC';
export const attCity=tz=>String(tz||'UTC').split('/').pop().replaceAll('_',' ');
export const AttCell=({children,sub,mono=false})=><div className={`att-cell ${mono?'ep-mono':''}`}><span>{children}</span>{sub&&<small>{sub}</small>}</div>;
export const attendanceColumns=[
  {key:'client',label:'Doctor',render:r=><AttCell sub={attCity(attZone(r))}>{r.client?.name||r.client?.business_name||'Legacy / unallocated'}</AttCell>},
  {key:'date',label:'Date',sortKey:'date',render:r=>{const d=r.date?new Date(String(r.date).slice(0,10)+'T00:00:00'):null;return <AttCell sub={d?d.toLocaleDateString('en-US',{weekday:'long'}):''}>{d?d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}):'—'}</AttCell>}},
  {key:'shift',label:'Shift',render:r=>r.shift_start_snapshot&&r.shift_end_snapshot?<AttCell>{attTime(r.shift_start_snapshot,attZone(r))} – {attTime(r.shift_end_snapshot,attZone(r))}</AttCell>:<span className="muted">—</span>},
  {key:'clock_in',label:'Clock in',sortKey:'clock_in',render:r=>r.clock_in?<AttCell mono sub={attDay(r.clock_in,attZone(r))!==attDay(r.shift_start_snapshot||r.clock_in,attZone(r))?attDay(r.clock_in,attZone(r)):''}>{attTime(r.clock_in,attZone(r))}</AttCell>:<span className="muted">—</span>},
  {key:'clock_out',label:'Clock out',sortKey:'clock_out',render:r=>r.clock_out?<AttCell mono sub={attDay(r.clock_out,attZone(r))!==attDay(r.clock_in,attZone(r))?attDay(r.clock_out,attZone(r)):''}>{attTime(r.clock_out,attZone(r))}</AttCell>:<Badge tone="success">Live</Badge>},
  {key:'breaks',label:'Break',sortKey:'break_taken',render:r=><span className="ep-mono">{Math.floor(Number(r.break_taken||0)/60)} min</span>},
  {key:'worked',label:'Regular',sortKey:'total_minutes',render:r=><span className="ep-mono">{mins(r.total_minutes)}</span>},
  {key:'late',label:'Today',sortKey:'late_minutes',render:r=>{const late=Number(r.late_minutes||0);return <Badge tone={late>0?'danger':'success'}>{late>0?`${durationHM(late)} late`:'On time'}</Badge>}},
  {key:'overtime',label:'Overtime',sortKey:'overtime',render:r=>{const ot=Number(r.overtime||0);return ot>0?<AttCell sub={String(r.overtime_status||'').replace('_',' ')}><Badge tone={r.overtime_status==='approved'?'success':'warning'}>{durationHM(ot)}</Badge></AttCell>:<span className="muted">—</span>}},
];

export const vaColumn={key:'employee',label:'Virtual Assistant',render:r=><div className="person-cell"><div className="avatar small"><AvatarMedia src={r.employee?.profile_picture} alt={`${r.employee?.first_name||''} ${r.employee?.last_name||''}`} fallback={`${r.employee?.first_name?.[0]||''}${r.employee?.last_name?.[0]||''}`||'?'}/></div><div><strong>{`${r.employee?.first_name||''} ${r.employee?.last_name||''}`.trim()||'—'}</strong><span>{r.employee?.employee_code||''}</span></div></div>};
