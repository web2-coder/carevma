import { useEffect, useMemo, useState } from 'react';
import { Banknote, Check, Download, Plus, Trash2 } from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { PageHeader, Card, Button, Input, Select, SearchableSelect, Textarea, SearchBox, Badge, statusTone, Loading, Notice, Modal, Table, Pagination } from '../components/ui';

const emptyForm = { employee_id:'', period_start:'', period_end:'', currency:'PKR', base_amount:'', bonus_amount:'', deduction_amount:'', notes:'' };
const amount = (value, currency='PKR') => `${currency} ${Number(value || 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`;

export default function SalarySlipsPage(){
  const { user } = useAuth();
  const isEmployee = user?.role === 'employee';
  const [data,setData]=useState(null),[page,setPage]=useState(1),[search,setSearch]=useState(''),[status,setStatus]=useState('');
  const [options,setOptions]=useState({employees:[]}),[modal,setModal]=useState(false),[form,setForm]=useState(emptyForm),[busy,setBusy]=useState(false),[notice,setNotice]=useState(null);
  const load=async()=>{try{const params={page};if(!isEmployee){if(search)params.search=search;if(status)params.status=status;}setData((await api.get(isEmployee?'/employee/salary-slips':'/management/salary-slips',{params})).data)}catch(e){setNotice({type:'error',text:messageFrom(e)})}};
  useEffect(()=>{load()},[page,status]);
  useEffect(()=>{if(isEmployee)return;api.get('/management/options').then(r=>setOptions(r.data||{employees:[]})).catch(()=>{})},[isEmployee]);
  const employeeOptions=useMemo(()=>(options.employees||[]).map(e=>({value:e.id,label:`${e.first_name||''} ${e.last_name||''}`.trim(),meta:[e.employee_code,e.designation?.name,e.department?.name].filter(Boolean).join(' · '),employee:e})),[options]);
  const selected=employeeOptions.find(e=>String(e.value)===String(form.employee_id))?.employee;
  const chooseEmployee=value=>{const emp=employeeOptions.find(e=>String(e.value)===String(value))?.employee;setForm(f=>({...f,employee_id:value,base_amount:f.currency==='USD'?(emp?.salary_usd??''):(emp?.salary_pkr??'')}))};
  const changeCurrency=value=>setForm(f=>({...f,currency:value,base_amount:value==='USD'?(selected?.salary_usd??''):(selected?.salary_pkr??'')}));
  const save=async()=>{setBusy(true);try{await api.post('/management/salary-slips',form);setModal(false);setForm(emptyForm);setNotice({type:'success',text:'Salary slip generated as draft.'});load()}catch(e){setNotice({type:'error',text:messageFrom(e)})}finally{setBusy(false)}};
  const approve=async row=>{if(!window.confirm(`Approve salary slip for ${row.employee?.first_name||'employee'}? Approved slips are locked.`))return;try{await api.post(`/management/salary-slips/${row.id}/approve`);setNotice({type:'success',text:'Salary slip approved.'});load()}catch(e){setNotice({type:'error',text:messageFrom(e)})}};
  const remove=async row=>{if(!window.confirm('Delete this draft salary slip?'))return;try{await api.delete(`/management/salary-slips/${row.id}`);setNotice({type:'success',text:'Draft salary slip deleted.'});load()}catch(e){setNotice({type:'error',text:messageFrom(e)})}};
  if(!data)return <Loading/>;
  const rows=data.data||[];
  const columns=[
    {key:'employee',label:'Employee',render:r=><div><strong>{r.employee?.first_name} {r.employee?.last_name}</strong><br/><small>{r.employee?.employee_code||'—'} · {r.employee?.designation?.name||r.employee?.department?.name||'Employee'}</small></div>},
    {key:'period',label:'Period',render:r=><span>{String(r.period_start||'').slice(0,10)} → {String(r.period_end||'').slice(0,10)}</span>},
    {key:'base_amount',label:'Base',render:r=>amount(r.base_amount,r.currency)},
    {key:'net_amount',label:'Net Salary',render:r=><strong>{amount(r.net_amount,r.currency)}</strong>},
    {key:'status',label:'Status',render:r=><Badge tone={statusTone(r.status)}>{r.status}</Badge>},
    {key:'actions',label:'Actions',render:r=><div className="row-actions"><a className="btn btn-ghost btn-link" href={`/salary-slips/${r.id}/pdf`} target="_blank" rel="noreferrer" title="Download salary slip PDF"><Download size={16}/></a>{!isEmployee&&r.status!=='approved'&&<><button title="Approve" onClick={()=>approve(r)}><Check size={16}/></button><button className="danger" title="Delete draft" onClick={()=>remove(r)}><Trash2 size={16}/></button></>}</div>}
  ];
  return <>
    <PageHeader eyebrow="Payroll" title={isEmployee?'My Salary Slips':'Salary Slips'} description={isEmployee?'View and download your approved salary slips.':'Generate and approve salary slips for every employee, including VAs, SEO and other non-VA staff.'} actions={!isEmployee&&<Button icon={Plus} onClick={()=>{setForm(emptyForm);setModal(true)}}>Generate Salary Slip</Button>}/>
    {notice&&<Notice type={notice.type} onClose={()=>setNotice(null)}>{notice.text}</Notice>}
    {!isEmployee&&<Card><div className="filter-grid"><SearchBox value={search} onChange={setSearch} placeholder="Search employee"/><Select label="Status" value={status} onChange={e=>{setStatus(e.target.value);setPage(1)}}><option value="">All statuses</option><option value="draft">Draft</option><option value="approved">Approved</option></Select><div className="filter-actions"><Button onClick={()=>{setPage(1);load()}}>Search</Button></div></div></Card>}
    <Card title={isEmployee?'Approved salary slips':'Payroll register'} subtitle={isEmployee?'Only approved slips are visible here.':'Draft first, then Admin/HR approval locks the slip.'}><Table columns={columns} rows={rows} emptyTitle="No salary slips found"/><Pagination meta={data} onPage={setPage}/></Card>
    {!isEmployee&&<Modal open={modal} onClose={()=>setModal(false)} title="Generate Salary Slip" wide footer={<><Button variant="ghost" onClick={()=>setModal(false)}>Cancel</Button><Button loading={busy} icon={Banknote} onClick={save}>Create Draft</Button></>}><div className="form-grid"><SearchableSelect label="Employee *" value={form.employee_id} onChange={chooseEmployee} options={employeeOptions} placeholder="Search employee" searchPlaceholder="Search name, code, department..."/><Select label="Currency *" value={form.currency} onChange={e=>changeCurrency(e.target.value)}><option value="PKR">PKR</option><option value="USD">USD</option></Select><Input label="Period start *" type="date" value={form.period_start} onChange={e=>setForm({...form,period_start:e.target.value})}/><Input label="Period end *" type="date" value={form.period_end} onChange={e=>setForm({...form,period_end:e.target.value})}/><Input label="Base salary" type="number" min="0" step="0.01" value={form.base_amount} onChange={e=>setForm({...form,base_amount:e.target.value})} hint="Auto-filled from the employee salary field; can be adjusted before approval."/><Input label="Bonus / allowance" type="number" min="0" step="0.01" value={form.bonus_amount} onChange={e=>setForm({...form,bonus_amount:e.target.value})}/><Input label="Deduction" type="number" min="0" step="0.01" value={form.deduction_amount} onChange={e=>setForm({...form,deduction_amount:e.target.value})}/><div className="field"><span>Estimated net</span><strong>{amount((Number(form.base_amount)||0)+(Number(form.bonus_amount)||0)-(Number(form.deduction_amount)||0),form.currency)}</strong></div><div className="wizard-span-2"><Textarea label="Notes" value={form.notes} onChange={e=>setForm({...form,notes:e.target.value})}/></div></div></Modal>}
  </>;
}
