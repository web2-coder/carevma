import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  Users, Stethoscope, Clock3, TimerReset, ReceiptText, Plus, Pencil, Trash2, RefreshCw,
  CalendarDays, UserCheck, CircleDollarSign, Check, X, Bell, Save, KeyRound, Building2, ShieldCheck, Send, Coffee, Activity
} from 'lucide-react';
import api, { messageFrom } from '../api';
import { DEFAULT_TIMEZONE, formatDate, formatDateTime, formatTime as formatTimeInZone, todayInZone, weekdayFromDateOnly } from '../timezone';
import { useAuth } from '../context/AuthContext';
import {
  PageHeader, Card, MetricCard, Button, Input, Select, Textarea, SearchBox, Badge, statusTone,
  EmptyState, Loading, Notice, Modal, Table, Pagination, money, mins, dateTime
} from '../components/ui';
import TimezonePicker from '../components/TimezonePicker';
import SalaryConverter from '../components/SalaryConverter';
import '../../css/live-workforce.css';
import '../../css/live-workforce-badge.css';
import '../../css/dual-timezone-tables.css';

const roleBase = role => role === 'hr' ? '/hr' : '/admin';
const getList = data => data?.data || [];

function useNotice(){const [notice,setNotice]=useState(null);return {notice,success:t=>t&&setNotice({type:'success',text:t}),error:t=>t&&setNotice({type:'error',text:t}),clear:()=>setNotice(null)}}
function NoticeBar({notice,clear}){return notice?<Notice type={notice.type} onClose={clear}>{notice.text}</Notice>:null}

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

export function ManagementDashboard(){
  const {user}=useAuth();
  const [data,setData]=useState(null);
  const [loading,setLoading]=useState(true);
  const n=useNotice();
  const load=async(silent=false)=>{if(!silent)setLoading(true);try{setData((await api.get('/management/dashboard')).data)}catch(e){if(!silent)n.error(messageFrom(e))}finally{if(!silent)setLoading(false)}};
  useEffect(()=>{
    load();
    const realtime=()=>load(true);
    const fallback=setInterval(realtime,15000);
    window.addEventListener('carevma:notification',realtime);
    window.addEventListener('focus',realtime);
    return()=>{clearInterval(fallback);window.removeEventListener('carevma:notification',realtime);window.removeEventListener('focus',realtime)};
  },[]);
  if(loading&&!data)return <Loading/>;

  const m=data?.metrics||{};
  const max=Math.max(...(data?.trend||[]).map(x=>x.sessions),1);
  const birthdays=Array.isArray(data?.birthdays_today)?data.birthdays_today:[];
  const billing=data?.auto_invoice_cycle||{};
  const billingGenerated=billing.status==='generated';
  const billingStatus=billingGenerated?'Generated':billing.status==='scheduled'?'Scheduled':'No billable invoices';
  const billingDate=value=>value?String(value).slice(0,10):'—';
  const finance=data?.finance||{};
  const financeTrend=Array.isArray(data?.finance_trend)?data.finance_trend:[];
  const financeMax=Math.max(...financeTrend.flatMap(x=>[Number(x.income)||0,Number(x.outgoing)||0]),1);

  return <>
    <PageHeader eyebrow={user.role==='hr'?'Human Resources':'Administration'} title="Operations dashboard" description="Live workforce activity, attendance throughput, birthday awareness and monthly billing status in one view." actions={<Button variant="secondary" icon={RefreshCw} onClick={()=>load()}>Refresh</Button>}/>
    <NoticeBar notice={n.notice} clear={n.clear}/>

    <div className="metrics-grid">
      <MetricCard label="Employees" value={m.employees} icon={Users} hint="Workforce profiles"/>
      <MetricCard label="Clients" value={m.clients} icon={Stethoscope} hint="Active client records"/>
      <MetricCard label="Live employees" value={m.active||0} icon={Activity} hint="Realtime · updates without reload" live/>
      <MetricCard label="Pending overtime" value={m.pending_overtime} icon={TimerReset} hint="Needs review"/>
      {user.role!=='hr'&&<MetricCard label="Invoices" value={m.invoices} icon={ReceiptText} hint="Billing records"/>}
    </div>

    <div className="dashboard-notice-grid">
      <Card className={`billing-cycle-card ${billingGenerated?'is-generated':''}`} title="Automatic monthly invoices" subtitle="25th → next month 26th · drafts generate after the cycle closes">
        <div className="billing-cycle-status-row">
          <div className="billing-cycle-icon"><ReceiptText size={21}/></div>
          <div className="billing-cycle-copy">
            <span>Current cycle</span>
            <strong>{billingDate(billing.period_start)} → {billingDate(billing.period_end)}</strong>
            <small>Processing: 27th at 12:15 AM · {user?.assigned_timezone||'CareVMA business timezone'}</small>
          </div>
          <Badge tone={billingGenerated?'success':billing.status==='scheduled'?'info':'neutral'}>{billingStatus}</Badge>
        </div>
        <div className="billing-cycle-stats">
          <div><span>Draft invoices</span><strong>{billing.generated_count||0}</strong></div>
          <div><span>VAs billed</span><strong>{billing.billed_va_count||0}</strong></div>
          <div><span>Total amount</span><strong>{money(billing.total_amount||0)}</strong></div>
        </div>
        <div className="billing-cycle-foot">
          <span>{billingGenerated?`Last auto generation: ${billing.last_generated_at?dateTime(billing.last_generated_at,user?.assigned_timezone):'completed'}`:'CareVMA will generate duplicate-safe editable drafts automatically.'}</span>
          {user.role!=='hr'&&<Link className="btn btn-secondary btn-link" to="/admin/invoices">Review invoices</Link>}
        </div>
      </Card>

      <Card className={`birthday-dashboard-card ${birthdays.length?'has-birthdays':''}`} title="Today’s birthdays" subtitle="Based on each VA’s assigned timezone">
        {birthdays.length?<div className="birthday-dashboard-list">{birthdays.map(person=><div className="birthday-dashboard-person" key={person.id}>
          <div className="birthday-dashboard-avatar">{person.profile_picture?<img src={person.profile_picture} alt=""/>:<span>{String(person.name||'VA').split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase()}</span>}</div>
          <div><strong>🎂 {person.name}</strong><span>{person.timezone}</span></div>
          <Badge tone="warning">Birthday today</Badge>
        </div>)}</div>:<div className="birthday-dashboard-empty"><strong>No birthdays today</strong><span>Tomorrow reminders are sent to Admin one day before a VA birthday.</span></div>}
      </Card>
    </div>

    {user.role!=='hr'&&<Card className="dashboard-finance-card" title="Company finance" subtitle="Current month cash position with a six-month income/outgoing trend">
      <div className="dashboard-finance-top">
        <div className="finance-kpi-row">
          <div><span>Money in</span><strong>{money(finance.incoming||0)}</strong></div>
          <div><span>Money out</span><strong>{money(finance.outgoing||0)}</strong></div>
          <div className={Number(finance.net||0)<0?'negative':''}><span>Net</span><strong>{money(finance.net||0)}</strong></div>
          <div><span>Outstanding</span><strong>{money(finance.outstanding||0)}</strong></div>
        </div>
        <Link className="btn btn-primary btn-link" to="/admin/company-finance">View Company Finance</Link>
      </div>
      <div className="finance-mini-legend"><span><i className="income"/>Income received</span><span><i className="expense"/>Outgoing</span></div>
      <div className="finance-dashboard-chart">{financeTrend.map(point=><div className="finance-chart-column" key={point.label}>
        <div className="finance-chart-bars"><span className="income" style={{height:`${Math.max(3,(Number(point.income)||0)/financeMax*100)}%`}} title={`Income ${money(point.income||0)}`}/><span className="expense" style={{height:`${Math.max(3,(Number(point.outgoing)||0)/financeMax*100)}%`}} title={`Outgoing ${money(point.outgoing||0)}`}/></div>
        <small>{point.label}</small>
      </div>)}</div>
    </Card>}

    <div className="dashboard-grid">
      <Card title="Attendance activity" subtitle="Sessions recorded this week"><div className="mini-chart">{(data?.trend||[]).map(x=><div className="chart-col" key={x.date}><div className="chart-value">{x.sessions}</div><div className="chart-bar"><span style={{height:`${Math.max(10,x.sessions/max*100)}%`}}/></div><div className="chart-label">{x.label}</div></div>)}</div></Card>
      <Card title="Recent attendance" subtitle="Latest workforce sessions"><div className="activity-list">{(data?.recent_attendance||[]).length?(data.recent_attendance.map(a=><div className="activity-row" key={a.id}><div className="avatar">{a.employee?.profile_picture?<img src={a.employee.profile_picture} alt=""/>:<>{a.employee?.first_name?.[0]}{a.employee?.last_name?.[0]}</>}</div><div><strong>{a.employee?`${a.employee.first_name} ${a.employee.last_name}`:'Unknown employee'}</strong><span>{a.date} · {a.clock_out?'Completed':'Live session'}</span></div><Badge tone={a.clock_out?'success':'warning'}>{a.clock_out?'Done':'Live'}</Badge></div>)):<EmptyState/>}</div></Card>
    </div>
  </>;
}

function durationLabel(totalSeconds){
  const total=Math.max(0,Math.floor(Number(totalSeconds)||0));
  const h=Math.floor(total/3600),m=Math.floor((total%3600)/60),sec=total%60;
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
}

function formatClockTime(value){
  const [hours='0',minutes='0']=String(value||'00:00').split(':');
  const h=Number(hours)||0;
  const suffix=h>=12?'PM':'AM';
  const display=h%12||12;
  return `${display}:${String(minutes).padStart(2,'0')} ${suffix}`;
}

function LiveDuration({baseSeconds=0}){
  const [seconds,setSeconds]=useState(Math.max(0,Math.floor(Number(baseSeconds)||0)));
  useEffect(()=>{
    const base=Math.max(0,Math.floor(Number(baseSeconds)||0));
    const started=performance.now();
    const tick=()=>setSeconds(base+Math.max(0,Math.floor((performance.now()-started)/1000)));
    tick();
    const id=setInterval(tick,1000);
    return()=>clearInterval(id);
  },[baseSeconds]);
  return <div className="live-time-cell"><strong className="live-duration-value">{durationLabel(seconds)}</strong><span>Actual elapsed time</span></div>;
}

function LiveBreakDuration({row}){
  const [current,setCurrent]=useState(Math.max(0,Math.floor(Number(row.current_break_seconds)||0)));
  useEffect(()=>{
    const base=Math.max(0,Math.floor(Number(row.current_break_seconds)||0));
    if(!row.is_on_break){setCurrent(0);return undefined}
    const started=performance.now();
    const tick=()=>setCurrent(base+Math.max(0,Math.floor((performance.now()-started)/1000)));
    tick();
    const id=setInterval(tick,1000);
    return()=>clearInterval(id);
  },[row.id,row.is_on_break,row.current_break_seconds]);
  const completed=Math.max(0,Math.floor(Number(row.completed_break_seconds)||0));
  const total=completed+(row.is_on_break?current:0);
  return <div className={`live-break-cell ${row.is_on_break?'is-on-break':''}`}>
    <strong>{row.is_on_break?`On break · ${durationLabel(current)}`:'Not on break'}</strong>
    <span>Total used · {durationLabel(total)}</span>
  </div>;
}

function ZoneTimeLine({label,time,timezone,tone=''}){
  return <div className={`live-zone-time-line ${tone}`}>
    <span className="live-zone-chip">{label}</span>
    <div><strong>{time||'—'}</strong><span>{timezone||'Timezone not assigned'}</span></div>
  </div>;
}

export function LiveUsersPage(){
  const [data,setData]=useState({server_now:null,total:0,working:0,on_break:0,sessions:[]});
  const [loading,setLoading]=useState(true);
  const n=useNotice();
  const load=async(silent=false)=>{
    if(!silent)setLoading(true);
    try{setData((await api.get('/management/live-users')).data)}
    catch(e){n.error(messageFrom(e))}
    finally{if(!silent)setLoading(false)}
  };
  useEffect(()=>{
    load();
    const poll=setInterval(()=>load(true),15000);
    const realtime=()=>load(true);
    const focus=()=>load(true);
    window.addEventListener('carevma:notification',realtime);
    window.addEventListener('focus',focus);
    return()=>{
      clearInterval(poll);
      window.removeEventListener('carevma:notification',realtime);
      window.removeEventListener('focus',focus);
    };
  },[]);
  if(loading&&!data.sessions.length)return <Loading/>;

  const columns=[
    {
      key:'employee',label:'Employee / VA',render:r=><div className="person-cell live-person-cell">
        <div className="avatar">{r.employee?.first_name?.[0]}{r.employee?.last_name?.[0]}</div>
        <div><strong>{r.employee?`${r.employee.first_name} ${r.employee.last_name}`:'Unknown employee'}</strong><span>{r.employee?.email||r.employee?.employee_code||'—'}</span></div>
      </div>
    },
    {
      key:'client',label:'Working for client',render:r=><div className="live-client-cell">
        <strong>{r.client?.name||'Unallocated'}</strong>
        <span>{r.client?.business_name||'No practice name'}</span>
        <span className="live-doctor-zone">Doctor timezone · {r.doctor_timezone||'Not assigned'}</span>
      </div>
    },
    {
      key:'shift',label:'Scheduled shift',render:r=>r.schedule?<div className="live-shift-cell">
        <strong>{r.schedule_doctor_label||`${formatClockTime(r.schedule.start_time)} – ${formatClockTime(r.schedule.end_time)}`}</strong>
        <span>Doctor source · {r.doctor_timezone||r.calculation_timezone||'—'}</span>
        {r.schedule_va_label&&<span>VA local · {r.schedule_va_label} · {r.va_timezone}</span>}
      </div>:<div className="live-shift-cell"><strong>Legacy session</strong><span>VA timezone · {r.va_timezone||'Timezone unavailable'}</span>{r.doctor_timezone&&<span>Doctor timezone · {r.doctor_timezone}</span>}</div>
    },
    {
      key:'clock_in',label:'Clocked in',render:r=><div className="live-clockin-cell dual-zone-clockin">
        <ZoneTimeLine label="VA" time={r.clock_in_va_label} timezone={r.va_timezone}/>
        <ZoneTimeLine label="Doctor" time={r.clock_in_doctor_label} timezone={r.doctor_timezone} tone="doctor"/>
      </div>
    },
    {
      key:'live',label:'Live time',render:r=><LiveDuration baseSeconds={r.elapsed_seconds}/>
    },
    {
      key:'break',label:'Break',render:r=><LiveBreakDuration row={r}/>
    },
    {
      key:'status',label:'Status',render:r=><div className="live-status-cell"><span className={`live-status-dot ${r.is_on_break?'break':'working'}`}/><Badge tone={r.is_on_break?'warning':'success'}>{r.is_on_break?'On break':'Working'}</Badge></div>
    },
  ];

  return <><PageHeader eyebrow="Real-time workforce" title="Live workforce" description="See who is clocked in right now, which client they are serving, both assigned timezones, actual elapsed session time and break state." actions={<Button variant="secondary" icon={RefreshCw} onClick={()=>load()}>Refresh</Button>}/><NoticeBar notice={n.notice} clear={n.clear}/>
    <div className="metrics-grid live-workforce-metrics"><MetricCard label="Live employees" value={data.total} icon={Activity} hint="Open attendance sessions" live/><MetricCard label="Working" value={data.working} icon={UserCheck} hint="Actively working"/><MetricCard label="On break" value={data.on_break} icon={Coffee} hint="Currently on break"/></div>
    <Card title="Currently active" subtitle="Elapsed time comes from the UTC attendance instant; VA and Doctor clock-in times are shown in their assigned timezones.">
      {data.sessions.length?<div className="live-workforce-table"><Table columns={columns} rows={data.sessions}/></div>:<EmptyState title="No one is live right now" description="Employees will appear here as soon as they clock in."/>}
    </Card>
  </>;
}

const emptyEmployee={first_name:'',last_name:'',email:'',password:'',phone:'',timezone:'Asia/Karachi',location:'',department_id:'',role_id:'',employment_type_id:'',shift_type_id:'',designation_id:'',employee_status_id:'',expertise_id:'',reporting_manager_id:'',salary_pkr:'',salary_usd:'',source_of_hire:'',date_of_joining:'',date_of_regularisation:'',gender:'',marital_status:'',date_of_birth:'',age:'',about_me_notes:''};
export function EmployeesPage(){
  const {user}=useAuth(); const navigate=useNavigate();
  const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[search,setSearch]=useState(''),[page,setPage]=useState(1),[busy,setBusy]=useState(false),[modal,setModal]=useState(false),[edit,setEdit]=useState(null),[form,setForm]=useState(emptyEmployee),[options,setOptions]=useState({}); const n=useNotice();
  const load=async()=>{setBusy(true);try{const [r,o]=await Promise.all([api.get('/management/employees',{params:{search,page,per_page:25}}),api.get('/management/options')]);setRows(r.data.data);setMeta(r.data);setOptions(o.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
  useEffect(()=>{const t=setTimeout(load,220);return()=>clearTimeout(t)},[search,page]);
  const openNew=()=>navigate(`${roleBase(user.role)}/employees/new`); const openEdit=row=>{setEdit(row);setForm({...emptyEmployee,...row,password:'',department_id:row.department_id||'',role_id:row.role_id||'',employment_type_id:row.employment_type_id||'',shift_type_id:row.shift_type_id||'',designation_id:row.designation_id||'',employee_status_id:row.employee_status_id||'',expertise_id:row.expertise_id||'',reporting_manager_id:row.reporting_manager_id||''});setModal(true)};
  const save=async()=>{setBusy(true);try{edit?await api.put(`/management/employees/${edit.id}`,form):await api.post('/management/employees',form);n.success(edit?'Employee updated.':'Employee created.');setModal(false);await load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
  const remove=async row=>{try{await api.delete(`/management/employees/${row.id}`);n.success('Employee deleted.');load()}catch(e){n.error(messageFrom(e))}};
  const resendInvite=async row=>{setBusy(true);try{const {data}=await api.post(`/management/employees/${row.id}/resend-invite`);if(data.activation_url&&navigator.clipboard){await navigator.clipboard.writeText(data.activation_url);n.success(`${data.message} Activation link copied.`)}else n.success(data.message)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
  const set=(k,v)=>setForm(f=>({...f,[k]:v})); const opts=(key)=>(options[key]||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>);
  const columns=[{key:'employee_code',label:'Emp #'},{key:'name',label:'Employee',render:r=><div className="person-cell"><div className="avatar">{r.first_name?.[0]}{r.last_name?.[0]}</div><div><strong>{r.first_name} {r.last_name}</strong><span>{r.email}</span></div></div>},{key:'department',label:'Department',render:r=>r.department?.name||'—'},{key:'designation',label:'Designation',render:r=>r.designation?.name||'—'},{key:'status',label:'Status',render:r=><Badge tone={statusTone(r.employee_status?.name)}>{r.employee_status?.name||'Not set'}</Badge>},{key:'timezone',label:'Timezone'},{key:'salary',label:'Salary',render:r=>r.salary_usd?money(r.salary_usd):'—'},{key:'actions',label:'Actions',render:r=><div className="row-actions"><button title="Resend activation invite" onClick={()=>resendInvite(r)}><Send size={16}/></button><button title="Edit employee" onClick={()=>openEdit(r)}><Pencil size={16}/></button><button title="Delete employee" className="danger" onClick={()=>remove(r)}><Trash2 size={16}/></button></div>}];
  return <><PageHeader eyebrow="Workforce" title="Employees" description="Manage VA identities, work details, hierarchy, timezone and compensation." actions={<Button icon={Plus} onClick={openNew}>Add employee</Button>}/><NoticeBar notice={n.notice} clear={n.clear}/><Card><div className="toolbar"><SearchBox value={search} onChange={v=>{setSearch(v);setPage(1)}} placeholder="Search name, email or employee #"/><span className="record-count">{meta?.total??rows.length} employees</span></div>{busy&&!rows.length?<Loading/>:<Table columns={columns} rows={rows} emptyTitle="No employees found" emptyDescription="Change your search or add the first employee."/>}<Pagination meta={meta} onPage={setPage}/></Card>
  <Modal open={modal} onClose={()=>setModal(false)} title="Edit employee" wide footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} icon={Save} onClick={save}>Save changes</Button></>}><div className="form-section"><h3>Identity & login</h3><div className="form-grid"><Input label="First name *" value={form.first_name||''} onChange={e=>set('first_name',e.target.value)}/><Input label="Last name *" value={form.last_name||''} onChange={e=>set('last_name',e.target.value)}/><Input label="Email *" type="email" value={form.email||''} onChange={e=>set('email',e.target.value)}/><Input label="Phone" value={form.phone||''} onChange={e=>set('phone',e.target.value)}/><Input label="Location" value={form.location||''} onChange={e=>set('location',e.target.value)}/><TimezonePicker label="Timezone *" value={form.timezone||''} onChange={value=>set('timezone',value)} onCitySelect={place=>setForm(current=>({...current,timezone:place.timezone,location:[place.city,place.country].filter(Boolean).join(', ')}))}/></div></div><div className="form-section"><h3>Work & hierarchy</h3><div className="form-grid"><Select label="Department" value={form.department_id||''} onChange={e=>set('department_id',e.target.value)}><option value="">Select</option>{opts('departments')}</Select><Select label="Role" value={form.role_id||''} onChange={e=>set('role_id',e.target.value)}><option value="">Select</option>{opts('roles')}</Select><Select label="Employment type" value={form.employment_type_id||''} onChange={e=>set('employment_type_id',e.target.value)}><option value="">Select</option>{opts('employment_types')}</Select><Select label="Shift type" value={form.shift_type_id||''} onChange={e=>set('shift_type_id',e.target.value)}><option value="">Select</option>{opts('shift_types')}</Select><Select label="Designation" value={form.designation_id||''} onChange={e=>set('designation_id',e.target.value)}><option value="">Select</option>{opts('designations')}</Select><Select label="Employee status" value={form.employee_status_id||''} onChange={e=>set('employee_status_id',e.target.value)}><option value="">Select</option>{opts('employee_statuses')}</Select><Select label="Expertise" value={form.expertise_id||''} onChange={e=>set('expertise_id',e.target.value)}><option value="">Select</option>{opts('expertises')}</Select><Select label="Reporting manager" value={form.reporting_manager_id||''} onChange={e=>set('reporting_manager_id',e.target.value)}><option value="">Select</option>{opts('reporting_managers')}</Select><Input label="Joining date" type="date" value={form.date_of_joining||''} onChange={e=>set('date_of_joining',e.target.value)}/><Input label="Regularisation date" type="date" value={form.date_of_regularisation||''} onChange={e=>set('date_of_regularisation',e.target.value)}/><SalaryConverter pkr={form.salary_pkr} usd={form.salary_usd} onPkrChange={value=>set('salary_pkr',value)} onUsdChange={value=>set('salary_usd',value)}/><Input label="Source of hire" value={form.source_of_hire||''} onChange={e=>set('source_of_hire',e.target.value)}/></div></div><div className="form-section"><h3>Personal</h3><div className="form-grid"><Select label="Gender" value={form.gender||''} onChange={e=>set('gender',e.target.value)}><option value="">Not specified</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></Select><Input label="Marital status" value={form.marital_status||''} onChange={e=>set('marital_status',e.target.value)}/><Input label="Date of birth" type="date" value={form.date_of_birth||''} onChange={e=>set('date_of_birth',e.target.value)}/><Input label="Age" type="number" value={form.age||''} onChange={e=>set('age',e.target.value)}/><Textarea label="About employee" value={form.about_me_notes||''} onChange={e=>set('about_me_notes',e.target.value)}/></div></div></Modal></>;
}

const emptyClient={name:'',email:'',password:'',business_name:'',phone_number:'',country:'United States',timezone:'America/New_York',per_hour_charges:'',contract_type_id:'',service_id:'',ring_center:false};
export function ClientsPage(){
 const navigate=useNavigate();
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[search,setSearch]=useState(''),[page,setPage]=useState(1),[modal,setModal]=useState(false),[edit,setEdit]=useState(null),[form,setForm]=useState(emptyClient),[options,setOptions]=useState({}),[busy,setBusy]=useState(false);const n=useNotice();
 const load=async()=>{setBusy(true);try{const [r,o]=await Promise.all([api.get('/admin/clients',{params:{search,page}}),api.get('/management/options')]);setRows(r.data.data);setMeta(r.data);setOptions(o.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{const t=setTimeout(load,200);return()=>clearTimeout(t)},[search,page]);
 const open=(r=null)=>{setEdit(r);setForm(r?{...emptyClient,...r,password:'',ring_center:!!r.ring_center}:emptyClient);setModal(true)};const set=(k,v)=>setForm(f=>({...f,[k]:v}));
 const save=async()=>{setBusy(true);try{edit?await api.put(`/admin/clients/${edit.id}`,form):await api.post('/admin/clients',form);n.success(edit?'Client updated.':'Client created.');setModal(false);load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};const remove=async r=>{try{await api.delete(`/admin/clients/${r.id}`);n.success('Client deleted.');load()}catch(e){n.error(messageFrom(e))}};
 const columns=[{key:'name',label:'Client',render:r=><div className="person-cell"><div className="avatar client-avatar">{r.name?.[0]}</div><div><strong className="client-profile-name-link" onClick={()=>navigate(`/admin/client/${r.id}/profile`)}>{r.name}</strong><span>{r.email}</span></div></div>},{key:'business_name',label:'Business'},{key:'country',label:'Country'},{key:'rate',label:'Rate / hr',render:r=>r.per_hour_charges?money(r.per_hour_charges):'—'},{key:'timezone',label:'Timezone'},{key:'service',label:'Service',render:r=>r.service?.name||'—'},{key:'actions',label:'Actions',render:r=><div className="row-actions"><button className="client-profile-open-btn" title="Open client profile" onClick={()=>navigate(`/admin/client/${r.id}/profile`)}><Stethoscope size={16}/></button><button title="Edit client" onClick={()=>open(r)}><Pencil size={16}/></button><button title="Delete client" className="danger" onClick={()=>remove(r)}><Trash2 size={16}/></button></div>}];
 return <><PageHeader eyebrow="Client operations" title="Clients" description="Manage doctor/client accounts, rates, contract classification and timezone." actions={<Button icon={Plus} onClick={()=>open()}>Add client</Button>}/><NoticeBar notice={n.notice} clear={n.clear}/><Card><div className="toolbar"><SearchBox value={search} onChange={v=>{setSearch(v);setPage(1)}} placeholder="Search client or business"/><span className="record-count">{meta?.total??rows.length} clients</span></div><Table columns={columns} rows={rows} emptyTitle="No clients found"/><Pagination meta={meta} onPage={setPage}/></Card><Modal open={modal} onClose={()=>setModal(false)} title={edit?'Edit client':'Add client'} wide footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={save} icon={Save}>Save client</Button></>}><div className="form-grid"><Input label="Name *" value={form.name||''} onChange={e=>set('name',e.target.value)}/><Input label="Email *" type="email" value={form.email||''} onChange={e=>set('email',e.target.value)}/>{!edit&&<Input label="Temporary password *" type="password" value={form.password||''} onChange={e=>set('password',e.target.value)}/>}<Input label="Business name" value={form.business_name||''} onChange={e=>set('business_name',e.target.value)}/><Input label="Phone" value={form.phone_number||''} onChange={e=>set('phone_number',e.target.value)}/><Input label="Country" value={form.country||''} onChange={e=>set('country',e.target.value)}/><TimezonePicker label="Timezone *" value={form.timezone||''} onChange={value=>set('timezone',value)} onCitySelect={place=>setForm(current=>({...current,timezone:place.timezone,country:place.country||current.country}))}/><Input label="Per hour charge" type="number" step="0.01" value={form.per_hour_charges||''} onChange={e=>set('per_hour_charges',e.target.value)}/><Select label="Service" value={form.service_id||''} onChange={e=>set('service_id',e.target.value)}><option value="">Select</option>{(options.services||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</Select><Select label="Contract type" value={form.contract_type_id||''} onChange={e=>set('contract_type_id',e.target.value)}><option value="">Select</option>{(options.contracts||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</Select><label className="check field-check"><input type="checkbox" checked={!!form.ring_center} onChange={e=>set('ring_center',e.target.checked)}/><span>Ring Center enabled</span></label></div></Modal></>;
}

function parseTime12(value='00:00'){
  const [rawHour='0',rawMinute='0']=String(value||'00:00').slice(0,5).split(':');
  const hour24=Math.max(0,Math.min(23,Number(rawHour)||0));
  const minute=Math.max(0,Math.min(59,Number(rawMinute)||0));
  return {
    hour:String(hour24%12||12),
    minute:String(minute).padStart(2,'0'),
    period:hour24>=12?'PM':'AM',
  };
}

function toTime24(hour,minute,period){
  let h=Math.max(1,Math.min(12,Number(hour)||12))%12;
  if(period==='PM')h+=12;
  const m=Math.max(0,Math.min(59,Number(minute)||0));
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

function formatStoredTime12(value){
  if(!value)return '—';
  const {hour,minute,period}=parseTime12(value);
  return `${hour}:${minute} ${period}`;
}

function formatDurationHM(value){
  const decimal=Number(value||0);
  if(!Number.isFinite(decimal)||decimal<0)return '0.00';
  const totalMinutes=Math.round(decimal*60);
  const hours=Math.floor(totalMinutes/60);
  const minutes=totalMinutes%60;
  return `${hours}.${String(minutes).padStart(2,'0')}`;
}

function Time12Field({label,value,onChange}){
  const parsed=parseTime12(value);
  const update=patch=>{
    const next={...parsed,...patch};
    onChange(toTime24(next.hour,next.minute,next.period));
  };
  return <div className="field">
    <span>{label}</span>
    <div style={{display:'grid',gridTemplateColumns:'1fr 1fr 1fr',gap:8}}>
      <select aria-label={`${label} hour`} value={parsed.hour} onChange={e=>update({hour:e.target.value})}>
        {Array.from({length:12},(_,i)=>String(i+1)).map(h=><option key={h} value={h}>{h}</option>)}
      </select>
      <select aria-label={`${label} minute`} value={parsed.minute} onChange={e=>update({minute:e.target.value})}>
        {Array.from({length:60},(_,i)=>String(i).padStart(2,'0')).map(m=><option key={m} value={m}>{m}</option>)}
      </select>
      <select aria-label={`${label} AM or PM`} value={parsed.period} onChange={e=>update({period:e.target.value})}>
        <option value="AM">AM</option>
        <option value="PM">PM</option>
      </select>
    </div>
    <small>Doctor local time: {formatStoredTime12(value)} · stored as {String(value||'').slice(0,5)}</small>
  </div>;
}

export function SchedulesPage(){
 const emptySchedule={employee_id:'',client_id:'',schedule_date:'',weekday:'Monday',start_time:'09:00',end_time:'17:00',timezone:'',enabled:true,repeat:true,per_hour_rate:''};
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[page,setPage]=useState(1),[options,setOptions]=useState({}),[modal,setModal]=useState(false),[edit,setEdit]=useState(null),[busy,setBusy]=useState(false),[form,setForm]=useState(emptySchedule);const n=useNotice();

 const dayFromDate=value=>weekdayFromDateOnly(value);
 const selectedClient=id=>(options.clients||[]).find(client=>String(client.id)===String(id));
 const selectedEmployee=id=>(options.employees||[]).find(employee=>String(employee.id)===String(id));
 const formatMoment=(value,timeZone)=>formatDateTime(value,timeZone||'UTC',{weekday:'short',year:undefined});
 const formatTime=(value,timeZone)=>formatTimeInZone(value,timeZone||'UTC');
 const doctorRange=row=>{const tz=row.client_timezone||row.client?.timezone;if(!row.client_shift_starts_at||!row.client_shift_ends_at||!tz)return 'Saved after timezone sync';return `${formatMoment(row.client_shift_starts_at,tz)} – ${formatTime(row.client_shift_ends_at,tz)}`};

 const load=async(nextPage=page)=>{setBusy(true);try{const [r,o]=await Promise.all([api.get('/management/schedules',{params:{paginate:1,page:nextPage,per_page:50}}),api.get('/management/options')]);setRows(r.data?.data||[]);setMeta(r.data||null);setOptions(o.data||{})}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 useEffect(()=>{load(page)},[page]);

 const open=r=>{
   setEdit(r||null);
   if(r){
     const employee=(options.employees||[]).find(x=>String(x.id)===String(r.employee_id));
     setForm({...emptySchedule,...r,schedule_date:r.schedule_date?String(r.schedule_date).slice(0,10):'',start_time:r.start_time?.slice(0,5),end_time:r.end_time?.slice(0,5),timezone:r.client_timezone||r.client?.timezone||r.timezone||'',enabled:!!r.enabled,repeat:!!r.repeat,per_hour_rate:''});
   }else{
     setForm(emptySchedule);
   }
   setModal(true);
 };
 const set=(k,v)=>setForm(f=>({...f,[k]:v}));
 const chooseEmployee=value=>setForm(current=>({...current,employee_id:value}));
 const chooseClient=value=>{const client=selectedClient(value);setForm(current=>({...current,client_id:value,timezone:client?.timezone||''}))};
 const chooseDate=value=>{const weekday=dayFromDate(value);setForm(current=>({...current,schedule_date:value,weekday:weekday||current.weekday,repeat:value?false:current.repeat}))};
 const save=async()=>{
   setBusy(true);
   try{
     const employee=selectedEmployee(form.employee_id);
     const client=selectedClient(form.client_id);
     if(!employee?.timezone){n.error('Selected VA / Employee must have a timezone before saving the schedule.');return}
     if(!client?.timezone){n.error('Selected Client / Doctor must have a timezone before saving the schedule.');return}
     const payload={...form,schedule_date:form.repeat?null:(form.schedule_date||null)};
     payload.timezone=client.timezone;
     edit?await api.put(`/management/schedules/${edit.id}`,payload):await api.post('/management/schedules',payload);
     n.success('Schedule saved. Doctor / Client local time controls check-in; VA local time is the converted same instant.');
     setModal(false);await load();
   }catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
 };
 const remove=async r=>{try{await api.delete(`/management/schedules/${r.id}`);n.success('Schedule deleted.');load()}catch(e){n.error(messageFrom(e))}};

 const currentEmployee=selectedEmployee(form.employee_id);
 const currentClient=selectedClient(form.client_id);
 const columns=[
   {key:'employee',label:'Employee',render:r=>`${r.employee?.first_name||''} ${r.employee?.last_name||''}`},
   {key:'client',label:'Client / Doctor',render:r=>r.client?.name||'—'},
   {key:'weekday',label:'Doctor day'},
   {key:'schedule_type',label:'Type',render:r=>r.repeat?<Badge tone="info">Weekly</Badge>:<Badge tone="neutral">{r.schedule_date?`Once · ${String(r.schedule_date).slice(0,10)}`:'Legacy weekly'}</Badge>},
   {key:'doctor_shift',label:'Doctor local shift',render:r=>`${formatStoredTime12(r.start_time)} – ${formatStoredTime12(r.end_time)}`},
   {key:'doctor_timezone',label:'Doctor timezone',render:r=>r.client_timezone||r.client?.timezone||r.timezone||'Not specified'},
   {key:'va_shift',label:'VA local time',render:r=>{const tz=r.employee_timezone||r.employee?.timezone;return r.shift_starts_at&&r.shift_ends_at&&tz?`${formatMoment(r.shift_starts_at,tz)} – ${formatTime(r.shift_ends_at,tz)}`:'—'}},
   {key:'va_timezone',label:'VA timezone',render:r=>r.employee_timezone||r.employee?.timezone||'Not specified'},
   {key:'hours',label:'Duration (H.MM)',render:r=><strong>{formatDurationHM(r.no_of_hours)}</strong>},
   {key:'status',label:'Status',render:r=><Badge tone={r.enabled?'success':'danger'}>{r.enabled?'Enabled':'Disabled'}</Badge>},
   {key:'actions',label:'Actions',render:r=><div className="row-actions"><button onClick={()=>open(r)}><Pencil size={16}/></button><button className="danger" onClick={()=>remove(r)}><Trash2 size={16}/></button></div>}
 ];

 return <>
   <PageHeader eyebrow="Assignment planning" title="Schedules" description="Enter the shift in the Client / Doctor local timezone. CareVMA uses that timezone for check-in, lateness and overtime, then converts the same instant for the VA." actions={<Button icon={Plus} onClick={()=>open()}>Add schedule</Button>}/>
   <NoticeBar notice={n.notice} clear={n.clear}/>
   <Card>{busy&&!rows.length?<Loading/>:<><Table columns={columns} rows={rows} emptyTitle="No schedules configured"/><Pagination meta={meta} onPage={setPage}/></>}</Card>
   <Modal open={modal} onClose={()=>setModal(false)} title={edit?'Edit schedule':'New schedule'} footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={save}>Save schedule</Button></>}>
     <div className="form-grid">
       <Select label="Employee / VA *" value={form.employee_id} onChange={e=>chooseEmployee(e.target.value)}><option value="">Select employee</option>{(options.employees||[]).map(x=><option key={x.id} value={x.id}>{x.employee_code} · {x.first_name} {x.last_name}</option>)}</Select>
       <Select label="Client / Doctor *" value={form.client_id} onChange={e=>chooseClient(e.target.value)}><option value="">Select client</option>{(options.clients||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</Select>
       <Input label={form.repeat?'Date (optional while weekly)':'Date *'} type="date" value={form.schedule_date||''} disabled={!!form.repeat} onChange={e=>chooseDate(e.target.value)}/>
       <Select label="Doctor day *" value={form.weekday} disabled={!!form.schedule_date} onChange={e=>set('weekday',e.target.value)}>{['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map(x=><option key={x}>{x}</option>)}</Select>
       <Time12Field label="Doctor start time *" value={form.start_time} onChange={value=>set('start_time',value)}/>
       <Time12Field label="Doctor end time *" value={form.end_time} onChange={value=>set('end_time',value)}/>
       <Input label="Doctor timezone · calculation source" value={currentClient?.timezone||form.timezone||'Select a client first'} disabled/>
       <Input label="VA timezone · display only" value={currentEmployee?.timezone||'Select an employee first'} disabled/>
       <Input label="Per-hour rate" type="number" step="0.01" value={form.per_hour_rate||''} onChange={e=>set('per_hour_rate',e.target.value)}/>
       <label className="check field-check"><input type="checkbox" checked={!!form.repeat} onChange={e=>setForm(current=>({...current,repeat:e.target.checked,schedule_date:e.target.checked?'':current.schedule_date}))}/><span>Repeat weekly</span></label>
       <label className="check field-check"><input type="checkbox" checked={!!form.enabled} onChange={e=>set('enabled',e.target.checked)}/><span>Schedule enabled</span></label>
     </div>
     <div className="notice notice-info" style={{marginTop:12}}>
       <strong>How time works:</strong> Enter the Doctor / Client local time explicitly with AM/PM. Example: 2:50 AM is stored as 02:50, while 2:50 PM is 14:50. Doctor timezone ({currentClient?.timezone||'select a client'}) controls check-in, late minutes and overtime. VA timezone ({currentEmployee?.timezone||'select a VA'}) is display-only conversion. Check-in opens 15 minutes before the Doctor-local start time.
     </div>
   </Modal>
 </>;
}

export function AttendancePage(){
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[options,setOptions]=useState({}),[filters,setFilters]=useState({employee_id:'',from:'',to:'',search:''}),[page,setPage]=useState(1),[busy,setBusy]=useState(false);const n=useNotice();
 const load=async(nextFilters=filters,nextPage=page)=>{setBusy(true);try{const [r,o]=await Promise.all([api.get('/management/attendance',{params:{...nextFilters,page:nextPage}}),api.get('/management/options')]);setRows(r.data.data);setMeta(r.data);setOptions(o.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load(filters,page)},[page]);const apply=()=>{setPage(1);load(filters,1)};const reset=()=>{const clean={employee_id:'',from:'',to:'',search:''};setFilters(clean);setPage(1);load(clean,1)};
 const columns=[{key:'employee',label:'Employee',render:r=>`${r.employee?.first_name||''} ${r.employee?.last_name||''}`},{key:'client',label:'Client',render:r=>r.client?.name||'Legacy / unallocated'},{key:'date',label:'Date',render:r=><DualDate value={r.clock_in||r.date} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} vaTz={r.employee_timezone||r.employee?.timezone||'UTC'}/>},{key:'clock_in',label:'Clock in',render:r=><DualTime value={r.clock_in} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} vaTz={r.employee_timezone||r.employee?.timezone||'UTC'}/>},{key:'clock_out',label:'Clock out',render:r=><DualTime value={r.clock_out} doctorTz={r.client_timezone||r.client?.timezone||'UTC'} vaTz={r.employee_timezone||r.employee?.timezone||'UTC'} live={!r.clock_out}/>},{key:'breaks',label:'Break',render:r=>`${Math.floor(Number(r.break_taken||0)/60)} min`},{key:'worked',label:'Regular',render:r=>mins(r.total_minutes)},{key:'late',label:'Late',render:r=>`${r.late_minutes||0} min`},{key:'overtime',label:'Overtime',render:r=><Badge tone={(r.overtime||0)>0?'warning':'neutral'}>{r.overtime||0} min</Badge>}];
 return <><PageHeader eyebrow="Time operations" title="Attendance" description="Doctor time is the calculation source. VA time is the same attendance instant converted to the employee timezone."/><NoticeBar notice={n.notice} clear={n.clear}/><Card><div className="filter-grid"><Select label="Employee" value={filters.employee_id} onChange={e=>setFilters({...filters,employee_id:e.target.value})}><option value="">All employees</option>{(options.employees||[]).map(x=><option key={x.id} value={x.id}>{x.first_name} {x.last_name}</option>)}</Select><Input label="From date" type="date" value={filters.from} onChange={e=>setFilters({...filters,from:e.target.value})}/><Input label="To date" type="date" value={filters.to} onChange={e=>setFilters({...filters,to:e.target.value})}/><Input label="Search" value={filters.search} onChange={e=>setFilters({...filters,search:e.target.value})} placeholder="Date, time or employee"/><div className="filter-actions"><Button onClick={apply}>Filter</Button><Button variant="ghost" onClick={reset}>Reset</Button></div></div><Table columns={columns} rows={rows} emptyTitle="No attendance records" emptyDescription="No attendance matches these filters."/><Pagination meta={meta} onPage={setPage}/></Card></>;
}

export function ChangeRequestsPage(){
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[busy,setBusy]=useState(false);const n=useNotice();const load=async()=>{setBusy(true);try{const r=await api.get('/management/change-requests');setRows(r.data.data);setMeta(r.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load()},[]);const review=async(r,decision)=>{const note=decision==='rejected'?prompt('Optional rejection note:','')||'':'';try{await api.post(`/management/change-requests/${r.id}/review`,{decision,note});n.success(`Request ${decision}.`);load()}catch(e){n.error(messageFrom(e))}};
 const columns=[{key:'employee',label:'Employee',render:r=>`${r.employee?.first_name||''} ${r.employee?.last_name||''}`},{key:'date',label:'Date',render:r=>String(r.date||'').slice(0,10)},{key:'old',label:'Recorded',render:r=>`${r.old_clock_in||'—'} → ${r.old_clock_out||'—'}`},{key:'requested',label:'Requested',render:r=>`${r.requested_clock_in} → ${r.requested_clock_out}`},{key:'reason',label:'Reason'},{key:'status',label:'Status',render:r=><Badge tone={statusTone(r.status)}>{r.status}</Badge>},{key:'actions',label:'Review',render:r=>r.status==='pending'?<div className="row-actions"><button className="approve" onClick={()=>review(r,'approved')}><Check size={16}/></button><button className="danger" onClick={()=>review(r,'rejected')}><X size={16}/></button></div>:<span className="muted">Reviewed</span>}];
 return <><PageHeader eyebrow="Attendance governance" title="Change requests" description="Review requested clock corrections with visible reasons and decision history."/><NoticeBar notice={n.notice} clear={n.clear}/><Card>{busy&&!rows.length?<Loading/>:<Table columns={columns} rows={rows} emptyTitle="No change requests"/>}</Card></>;
}

export function OvertimePage(){
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[busy,setBusy]=useState(false),[modal,setModal]=useState(false),[selected,setSelected]=useState(null),[form,setForm]=useState({decision:'approved',approved_minutes:'',overtime_amount:''});const n=useNotice();const load=async()=>{setBusy(true);try{const r=await api.get('/management/overtime');setRows(r.data.data);setMeta(r.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load()},[]);const open=r=>{setSelected(r);setForm({decision:'approved',approved_minutes:r.approved_overtime_minutes||r.overtime,overtime_amount:r.overtime_amount||''});setModal(true)};const save=async()=>{setBusy(true);try{await api.post(`/management/overtime/${selected.id}/review`,form);n.success('Overtime review saved.');setModal(false);load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 const columns=[{key:'employee',label:'Employee',render:r=>`${r.employee?.first_name||''} ${r.employee?.last_name||''}`},{key:'date',label:'Date',render:r=>String(r.date||'').slice(0,10)},{key:'overtime',label:'Calculated',render:r=>`${r.overtime} min`},{key:'approved',label:'Approved',render:r=>`${r.approved_overtime_minutes||0} min`},{key:'status',label:'Status',render:r=><Badge tone={statusTone(r.overtime_status||'pending')}>{r.overtime_status||'Pending'}</Badge>},{key:'amount',label:'Custom payment',render:r=>r.overtime_amount?money(r.overtime_amount):'—'},{key:'actions',label:'Action',render:r=><Button variant="secondary" onClick={()=>open(r)}>Review</Button>}];
 return <><PageHeader eyebrow="Compensation review" title="Overtime" description="Approve full or partial overtime, reject it, or set a custom approved payment."/><NoticeBar notice={n.notice} clear={n.clear}/><Card><Table columns={columns} rows={rows} emptyTitle="No overtime records"/></Card><Modal open={modal} onClose={()=>setModal(false)} title="Review overtime" footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={save}>Save decision</Button></>}><div className="form-grid one-col"><Select label="Decision" value={form.decision} onChange={e=>setForm({...form,decision:e.target.value})}><option value="approved">Approve</option><option value="rejected">Reject</option></Select>{form.decision==='approved'&&<><Input label="Approved minutes" type="number" min="0" max={selected?.overtime} value={form.approved_minutes} onChange={e=>setForm({...form,approved_minutes:e.target.value})}/><Input label="Custom overtime payment (optional)" type="number" step="0.01" value={form.overtime_amount} onChange={e=>setForm({...form,overtime_amount:e.target.value})}/></>}</div></Modal></>;
}

function billingEndForStart(startValue){
  if(!/^\d{4}-\d{2}-\d{2}$/.test(String(startValue||'')))return '';
  const [year,month]=String(startValue).split('-').map(Number);
  if(!year||!month)return '';
  const end=new Date(Date.UTC(year,month,26));
  return end.toISOString().slice(0,10);
}

function latestCompletedBillingCycle(timeZone=DEFAULT_TIMEZONE){
  const today=todayInZone(timeZone);
  const [year,month,day]=today.split('-').map(Number);
  const endMonthIndex=(month-1)+(day>=26?0:-1);
  const end=new Date(Date.UTC(year,endMonthIndex,26));
  const start=new Date(Date.UTC(end.getUTCFullYear(),end.getUTCMonth()-1,25));
  const iso=d=>`${d.getUTCFullYear()}-${String(d.getUTCMonth()+1).padStart(2,'0')}-${String(d.getUTCDate()).padStart(2,'0')}`;
  return {period_start:iso(start),period_end:iso(end)};
}

export function InvoicesPage(){
  const [rows,setRows]=useState([]);
  const [options,setOptions]=useState({});
  const [createOpen,setCreateOpen]=useState(false);
  const [editOpen,setEditOpen]=useState(false);
  const [busy,setBusy]=useState(false);
  const [createForm,setCreateForm]=useState(()=>({client_id:'',...latestCompletedBillingCycle()}));
  const [editForm,setEditForm]=useState(null);
  const n=useNotice();

  const load=async()=>{
    setBusy(true);
    try{
      const [r,o]=await Promise.all([api.get('/admin/invoices'),api.get('/management/options')]);
      setRows(r.data.data);
      setOptions(o.data);
    }catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
  };
  useEffect(()=>{load()},[]);

  const generate=async()=>{
    setBusy(true);
    try{
      await api.post('/admin/invoices/generate',createForm);
      n.success('Invoice generated as an editable draft.');
      setCreateOpen(false);
      setCreateForm({client_id:'',...latestCompletedBillingCycle()});
      await load();
    }catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
  };

  const openEdit=async invoice=>{
    setBusy(true);
    try{
      const {data}=await api.get(`/admin/invoices/${invoice.id}`);
      setEditForm({
        id:data.id,
        invoice_number:data.invoice_number,
        client_name:data.client?.name||'Client',
        period_start:String(data.period_start||'').slice(0,10),
        period_end:String(data.period_end||'').slice(0,10),
        status:data.status||'draft',
        lines:(data.salaries||[]).map(line=>({
          id:line.id,
          employee_id:line.employee_id,
          employee_name:`${line.employee?.first_name||''} ${line.employee?.last_name||''}`.trim(),
          total_hours:line.total_hours??0,
          total_late:line.total_late??0,
          total_overtime:line.total_overtime??0,
          salary_amount:line.salary_amount??0,
        })),
        adjustments:(data.adjustments||[]).map(item=>({
          employee_id:item.employee_id||'',
          direction:item.direction||'addition',
          category:item.category||'bonus',
          description:item.description||'',
          amount:item.amount??'',
        }))
      });
      setEditOpen(true);
    }catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
  };

  const changeLine=(index,key,value)=>{
    setEditForm(current=>({...current,lines:current.lines.map((line,i)=>i===index?{...line,[key]:value}:line)}));
  };

  const addAdjustment=()=>{
    setEditForm(current=>({...current,adjustments:[...(current.adjustments||[]),{
      employee_id:'',direction:'addition',category:'bonus',description:'',amount:''
    }]}));
  };
  const changeAdjustment=(index,key,value)=>{
    setEditForm(current=>({...current,adjustments:(current.adjustments||[]).map((item,i)=>i===index?{...item,[key]:value}:item)}));
  };
  const removeAdjustment=index=>{
    setEditForm(current=>({...current,adjustments:(current.adjustments||[]).filter((_,i)=>i!==index)}));
  };

  const saveEdit=async()=>{
    if(!editForm)return;
    setBusy(true);
    try{
      await api.put(`/admin/invoices/${editForm.id}`,{
        period_start:editForm.period_start,
        period_end:editForm.period_end,
        status:editForm.status,
        lines:editForm.lines.map(({id,total_hours,total_late,total_overtime,salary_amount})=>({id,total_hours,total_late,total_overtime,salary_amount})),
        adjustments:(editForm.adjustments||[]).filter(item=>Number(item.amount)>0).map(item=>({
          employee_id:item.employee_id||null,
          direction:item.direction,
          category:item.category,
          description:item.description||null,
          amount:Number(item.amount),
        }))
      });
      n.success('Invoice saved. Salary lines and bonus/deduction adjustments were recalculated.');
      setEditOpen(false);
      await load();
    }catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
  };

  const columns=[
    {key:'invoice_number',label:'Invoice #'},
    {key:'client',label:'Client',render:r=>r.client?.name||'—'},
    {key:'period',label:'Period',render:r=>`${String(r.period_start||'').slice(0,10)} → ${String(r.period_end||'').slice(0,10)}`},
    {key:'source',label:'Source',render:r=><Badge tone={r.auto_generated?'info':'neutral'}>{r.auto_generated?'Auto 27th':'Manual'}</Badge>},
    {key:'status',label:'Status',render:r=><Badge tone={r.status==='paid'?'success':r.status==='void'?'danger':r.status==='issued'?'info':'warning'}>{r.status||'draft'}</Badge>},
    {key:'hours',label:'Hours',render:r=>Number(r.total_hours||0).toFixed(2)},
    {key:'amount',label:'Amount',render:r=>money(r.total_amount)},
    {key:'action',label:'Actions',render:r=><div className="row-actions"><button onClick={()=>openEdit(r)} title="Edit invoice"><Pencil size={16}/></button><a className="btn btn-secondary btn-link" href={`/invoices/${r.id}/pdf?v=${encodeURIComponent(r.updated_at||Date.now())}`} target="_blank" rel="noreferrer">View PDF</a></div>}
  ];

  return <>
    <PageHeader
      eyebrow="Client billing"
      title="Invoices"
      description="Billing periods run from the 25th through the 26th of the following month. Drafts auto-generate on the 27th after the full cycle closes."
      actions={<Button icon={Plus} onClick={()=>{setCreateForm({client_id:'',...latestCompletedBillingCycle()});setCreateOpen(true)}}>Generate invoice</Button>}
    />
    <NoticeBar notice={n.notice} clear={n.clear}/>
    <Card><Table columns={columns} rows={rows} emptyTitle="No invoices yet"/></Card>

    <Modal open={createOpen} onClose={()=>setCreateOpen(false)} title="Generate invoice" footer={<><Button variant="ghost" onClick={()=>setCreateOpen(false)}>Cancel</Button><Button loading={busy} onClick={generate}>Generate draft</Button></>}>
      <div className="form-grid one-col">
        <Select label="Client *" value={createForm.client_id} onChange={e=>setCreateForm({...createForm,client_id:e.target.value})}>
          <option value="">Select client</option>{(options.clients||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}
        </Select>
        <Input label="Start date *" type="date" value={createForm.period_start} onChange={e=>setCreateForm({...createForm,period_start:e.target.value})}/>
        <Input label="End date *" type="date" min={createForm.period_start||undefined} value={createForm.period_end} onChange={e=>setCreateForm({...createForm,period_end:e.target.value})}/>
        <div className="invoice-cycle-hint">Manual invoices can use any custom date range. Automatic billing still runs on the 25th → next month 26th cycle and generates after the cycle closes.</div>
      </div>
    </Modal>

    <Modal open={editOpen} onClose={()=>setEditOpen(false)} title={editForm?`Edit ${editForm.invoice_number}`:'Edit invoice'} footer={<><Button variant="ghost" onClick={()=>setEditOpen(false)}>Cancel</Button><Button loading={busy} icon={Save} onClick={saveEdit}>Save invoice</Button></>}>
      {editForm&&<div className="form-grid one-col">
        <div className="muted">Client: <strong>{editForm.client_name}</strong></div>
        <div className="form-grid two-col">
          <Input label="Period from *" type="date" value={editForm.period_start} onChange={e=>setEditForm({...editForm,period_start:e.target.value})}/>
          <Input label="Period to *" type="date" value={editForm.period_end} onChange={e=>setEditForm({...editForm,period_end:e.target.value})}/>
        </div>
        <Select label="Invoice status" value={editForm.status} onChange={e=>setEditForm({...editForm,status:e.target.value})}>
          <option value="draft">Draft</option><option value="issued">Issued</option><option value="paid">Paid</option><option value="void">Void</option>
        </Select>
        <div className="invoice-edit-list">
          {editForm.lines.map((line,index)=><Card key={line.id} title={line.employee_name||`Employee line ${index+1}`}>
            <div className="form-grid two-col">
              <Input label="Total hours" type="number" min="0" step="0.01" value={line.total_hours} onChange={e=>changeLine(index,'total_hours',e.target.value)}/>
              <Input label="Base salary amount" type="number" min="0" step="0.01" value={line.salary_amount} onChange={e=>changeLine(index,'salary_amount',e.target.value)}/>
              <Input label="Late minutes" type="number" min="0" step="1" value={line.total_late} onChange={e=>changeLine(index,'total_late',e.target.value)}/>
              <Input label="Approved overtime minutes" type="number" min="0" step="1" value={line.total_overtime} onChange={e=>changeLine(index,'total_overtime',e.target.value)}/>
            </div>
          </Card>)}
        </div>

        <Card title="Bonus / Allowance / Deduction" subtitle="Optional manual adjustments. Attendance salary stays unchanged and adjustments are shown separately on the invoice PDF.">
          <div className="row-actions" style={{marginBottom:12}}><Button type="button" variant="secondary" icon={Plus} onClick={addAdjustment}>Add adjustment</Button></div>
          {(editForm.adjustments||[]).length===0?<div className="muted">No manual adjustments added.</div>:
            <div className="invoice-edit-list">{editForm.adjustments.map((item,index)=><div key={`adj-${index}`} className="card" style={{padding:14}}>
              <div className="form-grid two-col">
                <Select label="Apply to" value={item.employee_id} onChange={e=>changeAdjustment(index,'employee_id',e.target.value)}>
                  <option value="">Whole invoice / General</option>
                  {editForm.lines.map(line=><option key={line.employee_id} value={line.employee_id}>{line.employee_name}</option>)}
                </Select>
                <Select label="Adjustment type" value={item.direction} onChange={e=>changeAdjustment(index,'direction',e.target.value)}>
                  <option value="addition">Addition (+)</option>
                  <option value="deduction">Deduction (-)</option>
                </Select>
                <Select label="Category" value={item.category} onChange={e=>changeAdjustment(index,'category',e.target.value)}>
                  <option value="bonus">Bonus</option>
                  <option value="allowance">Allowance</option>
                  <option value="reimbursement">Reimbursement</option>
                  <option value="other">Other</option>
                </Select>
                <Input label="Amount" type="number" min="0.01" step="0.01" value={item.amount} onChange={e=>changeAdjustment(index,'amount',e.target.value)}/>
              </div>
              <div style={{marginTop:10}}><Input label="Note / description" value={item.description} onChange={e=>changeAdjustment(index,'description',e.target.value)} placeholder="e.g. Performance bonus, equipment reimbursement"/></div>
              <div className="row-actions" style={{marginTop:10,justifyContent:'flex-end'}}><Button type="button" variant="ghost" icon={Trash2} onClick={()=>removeAdjustment(index)}>Remove</Button></div>
            </div>)}</div>}

          {(()=>{
            const base=(editForm.lines||[]).reduce((sum,line)=>sum+(Number(line.salary_amount)||0),0);
            const adjustment=(editForm.adjustments||[]).reduce((sum,item)=>sum+((item.direction==='deduction'?-1:1)*(Number(item.amount)||0)),0);
            return <div className="invoice-cycle-hint" style={{marginTop:12}}><strong>Base salary:</strong> {money(base)} &nbsp; | &nbsp; <strong>Adjustments:</strong> {adjustment>=0?'+':''}{money(adjustment)} &nbsp; | &nbsp; <strong>Estimated total:</strong> {money(Math.max(0,base+adjustment))}</div>;
          })()}
        </Card>
      </div>}
    </Modal>
  </>;
}


export function CompanyFinancePage(){
  const today=todayInZone(DEFAULT_TIMEZONE);
  const [view,setView]=useState('month');
  const [date,setDate]=useState(today);
  const [data,setData]=useState(null);
  const [modal,setModal]=useState(false);
  const [busy,setBusy]=useState(false);
  const [form,setForm]=useState({entry_date:today,type:'expense',category:'General',amount:'',description:''});
  const n=useNotice();

  const load=async()=>{
    setBusy(true);
    try{setData((await api.get('/admin/company-finance',{params:{view,date}})).data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
  };
  useEffect(()=>{load()},[view,date]);

  const summary=data?.summary||{};
  const trend=Array.isArray(data?.trend)?data.trend:[];
  const max=Math.max(...trend.flatMap(x=>[Number(x.income)||0,Number(x.outgoing)||0]),1);
  const save=async()=>{
    setBusy(true);
    try{
      await api.post('/admin/company-finance/entries',form);
      n.success(`${form.type==='income'?'Income':'Expense'} entry saved.`);
      setModal(false);
      setForm({entry_date:date||today,type:'expense',category:'General',amount:'',description:''});
      await load();
    }catch(e){n.error(messageFrom(e))}finally{setBusy(false)}
  };
  const remove=async entry=>{
    try{await api.delete(`/admin/company-finance/entries/${entry.id}`);n.success('Finance entry deleted.');await load()}catch(e){n.error(messageFrom(e))}
  };
  const columns=[
    {key:'entry_date',label:'Date',render:r=>String(r.entry_date||'').slice(0,10)},
    {key:'type',label:'Type',render:r=><Badge tone={r.type==='income'?'success':'danger'}>{r.type}</Badge>},
    {key:'category',label:'Category'},
    {key:'description',label:'Description',render:r=>r.description||'—'},
    {key:'amount',label:'Amount',render:r=><strong className={r.type==='expense'?'finance-expense-text':'finance-income-text'}>{r.type==='expense'?'-':'+'}{money(r.amount)}</strong>},
    {key:'creator',label:'Added by',render:r=>r.creator?.name||'System'},
    {key:'actions',label:'',render:r=><button className="finance-delete-btn" title="Delete entry" onClick={()=>remove(r)}><Trash2 size={16}/></button>},
  ];

  return <>
    <PageHeader eyebrow="Company reporting" title="Company Finance" description="Track billed revenue, received income, expenses and net cash day-wise, month-wise and year-wise." actions={<Button icon={Plus} onClick={()=>{setForm({entry_date:date||today,type:'expense',category:'General',amount:'',description:''});setModal(true)}}>Add money in / out</Button>}/>
    <NoticeBar notice={n.notice} clear={n.clear}/>

    <Card className="finance-filter-card">
      <div className="finance-filter-row">
        <Select label="Report view" value={view} onChange={e=>setView(e.target.value)}><option value="day">Day wise</option><option value="month">Monthly</option><option value="year">Yearly</option></Select>
        <Input label="Report date" type="date" value={date} onChange={e=>setDate(e.target.value)}/>
        <div className="finance-period-label"><span>Reporting period</span><strong>{data?.from||'—'} → {data?.to||'—'}</strong></div>
        <Button variant="secondary" icon={RefreshCw} onClick={load}>Refresh</Button>
      </div>
    </Card>

    <div className="metrics-grid finance-metrics-grid">
      <MetricCard label="Billed revenue" value={money(summary.billed||0)} icon={ReceiptText} hint="All non-void invoices"/>
      <MetricCard label="Money received" value={money(summary.incoming||0)} icon={CircleDollarSign} hint="Paid invoices + manual income"/>
      <MetricCard label="Money out" value={money(summary.outgoing||0)} icon={Building2} hint="Company expenses"/>
      <MetricCard label="Net cash" value={money(summary.net||0)} icon={Activity} hint="Received minus outgoing"/>
      <MetricCard label="Outstanding" value={money(summary.outstanding||0)} icon={Clock3} hint="Billed but not paid"/>
    </div>

    <Card title="Income vs outgoing" subtitle={`${view==='year'?'Month-wise':view==='month'?'Day-wise':'Last 7 days'} financial movement`}>
      <div className="finance-mini-legend"><span><i className="income"/>Money in</span><span><i className="expense"/>Money out</span></div>
      <div className={`finance-main-chart ${view==='month'?'is-dense':''}`}>{trend.map((point,index)=><div className="finance-main-column" key={`${point.label}-${index}`}>
        <div className="finance-main-bars"><span className="income" style={{height:`${Math.max(2,(Number(point.income)||0)/max*100)}%`}} title={`Money in ${money(point.income||0)}`}/><span className="expense" style={{height:`${Math.max(2,(Number(point.outgoing)||0)/max*100)}%`}} title={`Money out ${money(point.outgoing||0)}`}/></div>
        <small>{point.label}</small>
      </div>)}</div>
    </Card>

    <Card title="Manual finance entries" subtitle="Record company expenses or non-invoice income. Paid invoice income is counted automatically.">
      {busy&&!data?<Loading/>:<Table columns={columns} rows={data?.entries||[]} emptyTitle="No manual finance entries" emptyDescription="Add an income or expense entry for this reporting period."/>}
    </Card>

    <Modal open={modal} onClose={()=>setModal(false)} title="Add finance entry" footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={save}>Save entry</Button></>}>
      <div className="form-grid one-col">
        <Input label="Date *" type="date" value={form.entry_date} onChange={e=>setForm({...form,entry_date:e.target.value})}/>
        <Select label="Money direction *" value={form.type} onChange={e=>setForm({...form,type:e.target.value})}><option value="income">Money in / Income</option><option value="expense">Money out / Expense</option></Select>
        <Input label="Category *" value={form.category} onChange={e=>setForm({...form,category:e.target.value})} placeholder="e.g. Payroll, Software, Office, Other income"/>
        <Input label="Amount *" type="number" min="0.01" step="0.01" value={form.amount} onChange={e=>setForm({...form,amount:e.target.value})}/>
        <Textarea label="Description" value={form.description} onChange={e=>setForm({...form,description:e.target.value})} placeholder="Optional note"/>
      </div>
    </Modal>
  </>;
}

const refTitles={'departments':'Departments','roles':'Employee Roles','employment-types':'Employment Types','shift-types':'Shift Types','designations':'Designations','employee-statuses':'Employee Statuses','expertises':'Expertise','reporting-managers':'Reporting Managers','services':'Client Services','contracts':'Contract Types'};
export function ReferencePage(){
 const {type}=useParams(); const [rows,setRows]=useState([]),[modal,setModal]=useState(false),[edit,setEdit]=useState(null),[form,setForm]=useState({name:'',description:''}),[busy,setBusy]=useState(false);const n=useNotice();const title=refTitles[type]||'Configuration';const load=async()=>{setBusy(true);try{setRows((await api.get(`/management/references/${type}`)).data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load()},[type]);const open=r=>{setEdit(r||null);setForm(r?{name:r.name,description:r.description||''}:{name:'',description:''});setModal(true)};const save=async()=>{setBusy(true);try{edit?await api.put(`/management/references/${type}/${edit.id}`,form):await api.post(`/management/references/${type}`,form);n.success('Configuration saved.');setModal(false);load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};const remove=async r=>{try{await api.delete(`/management/references/${type}/${r.id}`);n.success('Item deleted.');load()}catch(e){n.error(messageFrom(e))}};
 return <><PageHeader eyebrow="Configuration" title={title} description="Reference values used across CareVMA forms and employee/client records." actions={<Button icon={Plus} onClick={()=>open()}>Add item</Button>}/><NoticeBar notice={n.notice} clear={n.clear}/><Card><Table columns={[{key:'name',label:'Name'},{key:'description',label:'Description',render:r=>r.description||'—'},{key:'actions',label:'Actions',render:r=><div className="row-actions"><button onClick={()=>open(r)}><Pencil size={16}/></button><button className="danger" onClick={()=>remove(r)}><Trash2 size={16}/></button></div>}]} rows={rows} emptyTitle={`No ${title.toLowerCase()} configured`}/></Card><Modal open={modal} onClose={()=>setModal(false)} title={edit?'Edit item':'Add item'} footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={save}>Save</Button></>}><div className="form-grid one-col"><Input label="Name *" value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/>{type==='departments'&&<Textarea label="Description" value={form.description} onChange={e=>setForm({...form,description:e.target.value})}/>}</div></Modal></>;
}

export function NotificationsPage(){
 const [rows,setRows]=useState([]),[busy,setBusy]=useState(false);const n=useNotice();const load=async()=>{setBusy(true);try{setRows((await api.get('/notifications')).data.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{load()},[]);const read=async()=>{try{await api.post('/notifications/read-all');n.success('All notifications marked as read.');load()}catch(e){n.error(messageFrom(e))}};
 return <><PageHeader eyebrow="Activity center" title="Notifications" description="System events and operational updates." actions={<Button variant="secondary" icon={Check} onClick={read}>Mark all read</Button>}/><NoticeBar notice={n.notice} clear={n.clear}/><Card>{rows.length?<div className="notification-list">{rows.map(r=><div className={`notification-item ${r.is_read?'':'unread'}`} key={r.id}><div className="notification-icon"><Bell size={17}/></div><div><strong>{r.title||r.type||'Notification'}</strong><p>{r.message||(typeof r.data==='object'?(r.data.message||r.data.text||JSON.stringify(r.data)):String(r.data||''))}</p><span>{dateTime(r.created_at)}</span>{r.action_url&&<Link className="notification-action" to={r.action_url}>Open</Link>}</div>{!r.is_read&&<i/>}</div>)}</div>:<EmptyState title="No notifications"/>}</Card></>;
}

export function AccountPage(){
 const {user,refresh}=useAuth(); const [profile,setProfile]=useState({name:user?.name||'',email:user?.email||''}),[pw,setPw]=useState({current_password:'',password:'',password_confirmation:''}),[busy,setBusy]=useState(false);const n=useNotice();const saveProfile=async()=>{setBusy(true);try{await api.put('/management/account',profile);await refresh();n.success('Account details updated.')}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};const savePw=async()=>{setBusy(true);try{await api.put('/management/account/password',pw);setPw({current_password:'',password:'',password_confirmation:''});n.success('Password changed.')}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 return <><PageHeader eyebrow="Account security" title="My account" description="Update your administrator/HR identity and password."/><NoticeBar notice={n.notice} clear={n.clear}/><div className="two-col"><Card title="Profile" subtitle="These details identify your signed-in account."><div className="form-grid one-col"><Input label="Name" value={profile.name} onChange={e=>setProfile({...profile,name:e.target.value})}/><Input label="Email" type="email" value={profile.email} onChange={e=>setProfile({...profile,email:e.target.value})}/><Button loading={busy} icon={Save} onClick={saveProfile}>Save profile</Button></div></Card><Card title="Change password" subtitle="Current password verification is required."><div className="form-grid one-col"><Input label="Current password" type="password" value={pw.current_password} onChange={e=>setPw({...pw,current_password:e.target.value})}/><Input label="New password" type="password" value={pw.password} onChange={e=>setPw({...pw,password:e.target.value})}/><Input label="Confirm new password" type="password" value={pw.password_confirmation} onChange={e=>setPw({...pw,password_confirmation:e.target.value})}/><Button loading={busy} icon={KeyRound} onClick={savePw}>Change password</Button></div></Card></div></>;
}

export function HrTeamPage(){
 const [rows,setRows]=useState([]),[meta,setMeta]=useState(null),[search,setSearch]=useState(''),[modal,setModal]=useState(false),[edit,setEdit]=useState(null),[form,setForm]=useState({name:'',email:'',password:''}),[busy,setBusy]=useState(false);const n=useNotice();
 const load=async()=>{setBusy(true);try{const r=await api.get('/admin/hr-users',{params:{search}});setRows(r.data.data);setMeta(r.data)}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};useEffect(()=>{const t=setTimeout(load,180);return()=>clearTimeout(t)},[search]);
 const open=r=>{setEdit(r||null);setForm(r?{name:r.name,email:r.email,password:''}:{name:'',email:'',password:''});setModal(true)};
 const save=async()=>{setBusy(true);try{edit?await api.put(`/admin/hr-users/${edit.id}`,form):await api.post('/admin/hr-users',form);n.success(edit?'HR account updated.':'HR account created.');setModal(false);load()}catch(e){n.error(messageFrom(e))}finally{setBusy(false)}};
 const remove=async r=>{try{await api.delete(`/admin/hr-users/${r.id}`);n.success('HR account deleted.');load()}catch(e){n.error(messageFrom(e))}};
 return <><PageHeader eyebrow="Access management" title="HR team" description="Create dedicated HR logins with workforce, attendance, schedule and review access—without billing/client administration." actions={<Button icon={Plus} onClick={()=>open()}>Add HR user</Button>}/><NoticeBar notice={n.notice} clear={n.clear}/><Card><div className="toolbar"><SearchBox value={search} onChange={setSearch} placeholder="Search HR team"/><span className="record-count">{meta?.total??rows.length} HR users</span></div><Table columns={[{key:'name',label:'Name'},{key:'email',label:'Email'},{key:'last_seen_at',label:'Last active',render:r=>dateTime(r.last_seen_at)},{key:'actions',label:'Actions',render:r=><div className="row-actions"><button onClick={()=>open(r)}><Pencil size={16}/></button><button className="danger" onClick={()=>remove(r)}><Trash2 size={16}/></button></div>}]} rows={rows} emptyTitle="No HR accounts" emptyDescription="Add an HR user to give the HR portal its first login."/></Card><Modal open={modal} onClose={()=>setModal(false)} title={edit?'Edit HR account':'Add HR account'} footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} onClick={save}>Save HR account</Button></>}><div className="form-grid one-col"><Input label="Name *" value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/><Input label="Email *" type="email" value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/><Input label={edit?'New password (optional)':'Password *'} type="password" value={form.password} onChange={e=>setForm({...form,password:e.target.value})}/></div></Modal></>;
}
