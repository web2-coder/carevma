import '../../css/employee-wizard.css';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Check, ClipboardCopy, FileText, Send, UploadCloud,
  UserRound, BriefcaseBusiness, Network, HeartHandshake, Clock3, ShieldCheck
} from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { Button, Card, Input, Notice, Select, Textarea } from '../components/ui';
import TimezonePicker from '../components/TimezonePicker';
import SalaryConverter from '../components/SalaryConverter';

const emptyEmployee = {
  first_name:'', last_name:'', email:'', phone:'', location:'', timezone:'Asia/Karachi',
  department_id:'', role_id:'', employment_type_id:'', shift_type_id:'', designation_id:'',
  employee_status_id:'', expertise_id:'', reporting_manager_id:'', salary_pkr:'', salary_usd:'',
  source_of_hire:'', date_of_joining:'', date_of_regularisation:'',
  gender:'', marital_status:'', date_of_birth:'', age:'', about_me_notes:''
};

const steps = [
  { key:'basic', label:'Basic', icon:UserRound },
  { key:'work', label:'Work', icon:BriefcaseBusiness },
  { key:'hierarchy', label:'Hierarchy', icon:Network },
  { key:'personal', label:'Personal', icon:HeartHandshake },
  { key:'documents', label:'Documents', icon:FileText },
  { key:'timezone', label:'Timezone', icon:Clock3 },
];

const emailOk = value => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());

export default function EmployeeWizardPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const base = user?.role === 'hr' ? '/hr' : '/admin';
  const [step, setStep] = useState(0);
  const [form, setForm] = useState(emptyEmployee);
  const [options, setOptions] = useState({});
  const [errors, setErrors] = useState({});
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(false);
  const [profilePicture, setProfilePicture] = useState(null);
  const [documents, setDocuments] = useState([]);
  const [result, setResult] = useState(null);

  useEffect(() => {
    api.get('/management/options')
      .then(({data}) => setOptions(data))
      .catch(error => { const message=messageFrom(error); if(message) setNotice({type:'error', text:message}); });
  }, []);

  const set = (key, value) => {
    setForm(current => ({...current, [key]: value}));
    setErrors(current => ({...current, [key]: undefined}));
  };

  const opts = key => (options[key] || []).map(item => <option key={item.id} value={item.id}>{item.name}</option>);

  const validateStep = index => {
    const next = {};
    if (index === 0) {
      if (!form.first_name.trim()) next.first_name = 'First name is required.';
      if (!form.last_name.trim()) next.last_name = 'Last name is required.';
      if (!form.email.trim()) next.email = 'Email is required.';
      else if (!emailOk(form.email)) next.email = 'Enter a valid email address.';
    }
    if (index === 5 && !form.timezone) next.timezone = 'Timezone is required.';
    setErrors(current => ({...current, ...next}));
    return Object.keys(next).length === 0;
  };

  const canReach = target => {
    if (target <= step) return true;
    for (let i = 0; i < target; i += 1) {
      if (!validateStep(i)) {
        setStep(i);
        setNotice({type:'error', text:`Complete the required fields in ${steps[i].label} before continuing.`});
        return false;
      }
    }
    return true;
  };

  const goTo = target => {
    if (canReach(target)) {
      setNotice(null);
      setStep(target);
      window.scrollTo({top:0, behavior:'smooth'});
    }
  };

  const next = () => {
    if (!validateStep(step)) {
      setNotice({type:'error', text:'Please complete the required fields before moving to the next step.'});
      return;
    }
    setNotice(null);
    setStep(current => Math.min(current + 1, steps.length - 1));
  };

  const submit = async () => {
    for (let i = 0; i < steps.length; i += 1) {
      if (!validateStep(i)) {
        setStep(i);
        setNotice({type:'error', text:`Complete the required fields in ${steps[i].label}.`});
        return;
      }
    }

    const payload = new FormData();
    Object.entries(form).forEach(([key, value]) => {
      if (value !== null && value !== undefined) payload.append(key, value);
    });
    if (profilePicture) payload.append('profile_picture', profilePicture);
    documents.forEach(file => payload.append('upload_documents[]', file));

    setBusy(true);
    setNotice(null);
    try {
      const {data} = await api.post('/management/employees', payload);
      setResult(data);
    } catch (error) {
      const responseErrors = error?.response?.data?.errors || {};
      const firstKey = Object.keys(responseErrors)[0];
      if (firstKey) {
        const stepMap = {
          first_name:0,last_name:0,email:0,phone:0,
          department_id:1,role_id:1,employment_type_id:1,shift_type_id:1,designation_id:1,employee_status_id:1,
          salary_pkr:1,salary_usd:1,source_of_hire:1,date_of_joining:1,date_of_regularisation:1,
          expertise_id:2,reporting_manager_id:2,
          gender:3,marital_status:3,date_of_birth:3,age:3,about_me_notes:3,
          profile_picture:4,upload_documents:4,
          timezone:5,location:5,
        };
        setStep(stepMap[firstKey] ?? 0);
        setErrors(current => ({...current, ...Object.fromEntries(Object.entries(responseErrors).map(([k,v]) => [k, Array.isArray(v) ? v[0] : v]))}));
      }
      { const message=messageFrom(error); if(message) setNotice({type:'error', text:message}); }
    } finally {
      setBusy(false);
    }
  };

  const copyInvite = async () => {
    if (!result?.activation_url) return;
    await navigator.clipboard.writeText(result.activation_url);
    setNotice({type:'success', text:'Activation link copied.'});
  };

  const completion = useMemo(() => steps.map((_, index) => index < step), [step]);

  if (result) {
    return <div className="employee-wizard-page carevma-onboarding">
      <div className="wizard-success-card">
        <div className="wizard-success-icon"><ShieldCheck size={34}/></div>
        <span className="eyebrow">VA account created</span>
        <h1>{form.first_name} {form.last_name}</h1>
        <p>{result.invitation_sent
          ? `An activation email was sent to ${form.email}. The VA will choose their own password.`
          : `The account was created, but email delivery did not complete. Copy the activation link and send it securely to ${form.email}.`}</p>
        {result.activation_url && <div className="invite-link-box"><code>{result.activation_url}</code><Button variant="secondary" icon={ClipboardCopy} onClick={copyInvite}>Copy link</Button></div>}
        {notice && <Notice type={notice.type}>{notice.text}</Notice>}
        <div className="wizard-success-actions">
          <Button variant="secondary" onClick={() => navigate(`${base}/employees`)}>Employees</Button>
          <Button onClick={() => { setResult(null); setForm(emptyEmployee); setStep(0); setProfilePicture(null); setDocuments([]); }}>Add another VA</Button>
        </div>
      </div>
    </div>;
  }

  return <div className="employee-wizard-page carevma-onboarding">
    <div className="employee-wizard-head">
      <div>
        <span className="eyebrow">Workforce onboarding</span>
        <h1>Add Employee / VA</h1>
        <p>Complete each step. Required fields must be valid before the next step unlocks.</p>
      </div>
      <Link className="btn btn-ghost btn-link" to={`${base}/employees`}>Employees</Link>
    </div>

    {notice && <Notice type={notice.type} onClose={() => setNotice(null)}>{notice.text}</Notice>}

    <div className="employee-wizard-layout carevma-onboarding-shell">
      <aside className="employee-wizard-steps" aria-label="Employee onboarding steps">
        {steps.map((item, index) => {
          const Icon = item.icon;
          const active = index === step;
          const complete = completion[index];
          return <button key={item.key} type="button" className={`wizard-step ${active?'active':''} ${complete?'complete':''}`} onClick={() => goTo(index)}>
            <span className="wizard-step-icon">{complete ? <Check size={16}/> : <Icon size={17}/>}</span>
            <span><strong>{item.label}</strong><small>Step {index + 1}</small></span>
          </button>;
        })}
      </aside>

      <Card className="employee-wizard-card carevma-onboarding-card">
        <div className="wizard-card-head">
          <div><span>Step {step + 1} of {steps.length}</span><h2>{steps[step].label}</h2></div>
          <div className="wizard-progress"><span style={{width:`${((step + 1) / steps.length) * 100}%`}}/></div>
        </div>

        {step === 0 && <div className="form-grid">
          <Input label="First name *" value={form.first_name} error={errors.first_name} onChange={e => set('first_name', e.target.value)} placeholder="John"/>
          <Input label="Last name *" value={form.last_name} error={errors.last_name} onChange={e => set('last_name', e.target.value)} placeholder="Doe"/>
          <Input label="Email *" type="email" value={form.email} error={errors.email} onChange={e => set('email', e.target.value)} placeholder="john.doe@example.com"/>
          <Input label="Phone" value={form.phone} error={errors.phone} onChange={e => set('phone', e.target.value)} placeholder="+1 555 123 4567"/>
          <div className="wizard-info-box"><ShieldCheck size={18}/><div><strong>No Admin-created password</strong><p>The VA receives an activation email and chooses their own password securely.</p></div></div>
        </div>}

        {step === 1 && <div className="form-grid">
          <Select label="Department" value={form.department_id} onChange={e => set('department_id', e.target.value)}><option value="">Select department</option>{opts('departments')}</Select>
          <Select label="Employee role" value={form.role_id} onChange={e => set('role_id', e.target.value)}><option value="">Select role</option>{opts('roles')}</Select>
          <Select label="Employment type" value={form.employment_type_id} onChange={e => set('employment_type_id', e.target.value)}><option value="">Select type</option>{opts('employment_types')}</Select>
          <Select label="Shift type" value={form.shift_type_id} onChange={e => set('shift_type_id', e.target.value)}><option value="">Select shift</option>{opts('shift_types')}</Select>
          <Select label="Designation" value={form.designation_id} onChange={e => set('designation_id', e.target.value)}><option value="">Select designation</option>{opts('designations')}</Select>
          <Select label="Employee status" value={form.employee_status_id} onChange={e => set('employee_status_id', e.target.value)}><option value="">Select status</option>{opts('employee_statuses')}</Select>
          <Input label="Joining date" type="date" value={form.date_of_joining} onChange={e => set('date_of_joining', e.target.value)}/>
          <Input label="Regularisation date" type="date" value={form.date_of_regularisation} onChange={e => set('date_of_regularisation', e.target.value)}/>
          <Input label="Source of hire" value={form.source_of_hire} onChange={e => set('source_of_hire', e.target.value)} placeholder="Referral, LinkedIn, Upwork…"/>
          <div className="wizard-span-2"><SalaryConverter pkr={form.salary_pkr} usd={form.salary_usd} onPkrChange={value => set('salary_pkr', value)} onUsdChange={value => set('salary_usd', value)}/></div>
        </div>}

        {step === 2 && <div className="form-grid">
          <Select label="Reporting manager" value={form.reporting_manager_id} onChange={e => set('reporting_manager_id', e.target.value)}><option value="">No reporting manager</option>{opts('reporting_managers')}</Select>
          <Select label="Expertise" value={form.expertise_id} onChange={e => set('expertise_id', e.target.value)}><option value="">Select expertise</option>{opts('expertises')}</Select>
          <div className="wizard-info-box wizard-span-2"><Network size={18}/><div><strong>Hierarchy can be updated later</strong><p>Leaving these fields blank will not block onboarding.</p></div></div>
        </div>}

        {step === 3 && <div className="form-grid">
          <Select label="Gender" value={form.gender} onChange={e => set('gender', e.target.value)}><option value="">Not specified</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></Select>
          <Input label="Marital status" value={form.marital_status} onChange={e => set('marital_status', e.target.value)} placeholder="Single / Married"/>
          <Input label="Date of birth" type="date" value={form.date_of_birth} onChange={e => set('date_of_birth', e.target.value)}/>
          <Input label="Age" type="number" min="16" max="100" value={form.age} error={errors.age} onChange={e => set('age', e.target.value)}/>
          <div className="wizard-span-2"><Textarea label="About employee" value={form.about_me_notes} onChange={e => set('about_me_notes', e.target.value)} placeholder="Optional notes about the VA"/></div>
        </div>}

        {step === 4 && <div className="documents-step">
          <label className="upload-card">
            <UploadCloud size={24}/><strong>Profile photo</strong><span>JPG, JPEG or PNG · max 800 KB</span>
            <input type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" onChange={e => setProfilePicture(e.target.files?.[0] || null)}/>
            <em>{profilePicture ? profilePicture.name : 'Choose image'}</em>
          </label>
          <label className="upload-card">
            <FileText size={24}/><strong>Employee documents</strong><span>PDF, DOC, DOCX, JPG or PNG · max 5 MB each</span>
            <input type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={e => setDocuments(Array.from(e.target.files || []))}/>
            <em>{documents.length ? `${documents.length} file(s) selected` : 'Choose documents'}</em>
          </label>
          <div className="wizard-info-box"><ShieldCheck size={18}/><div><strong>Documents are optional during Admin onboarding</strong><p>The VA can upload or update their own permitted documents later from My Profile.</p></div></div>
        </div>}

        {step === 5 && <div className="form-grid one-col">
          <Input label="Location / City" value={form.location} onChange={e => set('location', e.target.value)} placeholder="Lahore, Pakistan"/>
          <TimezonePicker label="Timezone *" value={form.timezone} error={errors.timezone} onChange={value => set('timezone', value)} onCitySelect={place => setForm(current => ({...current, timezone:place.timezone, location:[place.city, place.country].filter(Boolean).join(', ')}))}/>
          <div className="wizard-review-box">
            <strong>Ready to create VA</strong>
            <p>{form.first_name} {form.last_name} · {form.email}</p>
            <p>After creation CareVMA sends a secure activation email. The VA sets their own password, then signs in as Team Member / VA.</p>
          </div>
        </div>}

        <div className="wizard-actions">
          <Button variant="ghost" icon={ArrowLeft} disabled={step === 0 || busy} onClick={() => setStep(current => Math.max(0, current - 1))}>Back</Button>
          <div>
            <Button variant="ghost" disabled={busy} onClick={() => navigate(`${base}/employees`)}>Cancel</Button>
            {step < steps.length - 1
              ? <Button icon={ArrowRight} onClick={next}>Next</Button>
              : <Button icon={Send} loading={busy} onClick={submit}>Create VA & Send Invite</Button>}
          </div>
        </div>
      </Card>
    </div>
  </div>;
}
