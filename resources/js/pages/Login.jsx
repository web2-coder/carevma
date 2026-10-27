import { useState } from 'react';
import { useNavigate, Link, useSearchParams, useParams } from 'react-router-dom';
import { ArrowRight, Eye, EyeOff, LockKeyhole, Mail, ShieldCheck } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import api, { messageFrom } from '../api';
import { Button, Input, Notice } from '../components/ui';
import BrandLogo from '../components/BrandLogo';
import '../../css/carevma-brand-clock.css';

export function LoginPage() {
  const { login } = useAuth(); const navigate = useNavigate();
  const [form, setForm] = useState({ email: '', password: '', remember: false });
  const [show, setShow] = useState(false); const [error, setError] = useState(''); const [loading, setLoading] = useState(false);
  const submit = async e => { e.preventDefault(); setLoading(true); setError(''); try { const data = await login(form); navigate(data.redirect, { replace: true }); } catch (e) { setError(messageFrom(e)); } finally { setLoading(false); } };
  return <div className="auth-page">
    <section className="auth-visual"><div className="auth-orb orb-one"/><div className="auth-orb orb-two"/><div className="auth-brand"><BrandLogo/></div><div className="auth-hero"><span className="auth-kicker"><ShieldCheck size={16}/> Secure workforce operations</span><h1>One workspace for your people, time, approvals and billing.</h1><p>Run employee schedules, attendance, corrections, overtime reviews and client visibility from a single role-aware platform.</p><div className="auth-stats"><div><strong>4</strong><span>Role portals</span></div><div><strong>1</strong><span>Connected workflow</span></div><div><strong>24/7</strong><span>Operational visibility</span></div></div></div></section>
    <section className="auth-panel"><div className="login-card"><div className="mobile-brand"><BrandLogo/></div><div className="auth-copy"><span className="eyebrow">Welcome back</span><h2>Sign in to your workspace</h2><p>Your destination is selected automatically based on your role.</p></div>{error && <Notice type="error" onClose={() => setError('')}>{error}</Notice>}<form onSubmit={submit} className="auth-form"><label className="field icon-field"><span>Email address</span><div><Mail size={17}/><input type="email" autoComplete="email" required value={form.email} onChange={e => setForm({...form, email:e.target.value})} placeholder="you@company.com"/></div></label><label className="field icon-field"><span>Password</span><div><LockKeyhole size={17}/><input type={show ? 'text' : 'password'} autoComplete="current-password" required value={form.password} onChange={e => setForm({...form,password:e.target.value})} placeholder="Enter your password"/><button type="button" onClick={() => setShow(v => !v)}>{show ? <EyeOff size={17}/> : <Eye size={17}/>}</button></div></label><div className="auth-row"><label className="check"><input type="checkbox" checked={form.remember} onChange={e => setForm({...form,remember:e.target.checked})}/><span>Keep me signed in</span></label><Link to="/forgot-password">Forgot password?</Link></div><Button loading={loading} className="wide" icon={ArrowRight}>Sign in</Button></form><div className="auth-footnote">Authorized users only · Protected by Laravel session authentication</div></div></section>
  </div>;
}

export function ForgotPasswordPage() {
  const [email,setEmail]=useState(''); const [busy,setBusy]=useState(false); const [notice,setNotice]=useState(null);
  const submit=async e=>{e.preventDefault();setBusy(true);setNotice(null);try{const {data}=await api.post('/forgot-password',{email});setNotice({type:'success',text:data.message});}catch(e){setNotice({type:'error',text:messageFrom(e)});}finally{setBusy(false)}};
  return <SimpleAuth title="Reset your password" subtitle="Enter your account email and we’ll send the reset link.">{notice&&<Notice type={notice.type}>{notice.text}</Notice>}<form onSubmit={submit} className="auth-form"><Input label="Email address" type="email" required value={email} onChange={e=>setEmail(e.target.value)} placeholder="you@company.com"/><Button loading={busy} className="wide">Send reset link</Button><Link className="text-link center" to="/login">Back to sign in</Link></form></SimpleAuth>;
}

export function ResetPasswordPage() {
  const [params]=useSearchParams(); const { token }=useParams(); const [form,setForm]=useState({email:params.get('email')||'',password:'',password_confirmation:''}); const [busy,setBusy]=useState(false); const [notice,setNotice]=useState(null); const navigate=useNavigate();
  const submit=async e=>{e.preventDefault();setBusy(true);setNotice(null);try{const {data}=await api.post('/reset-password',{...form,token});setNotice({type:'success',text:data.message});setTimeout(()=>navigate('/login'),700);}catch(e){setNotice({type:'error',text:messageFrom(e)});}finally{setBusy(false)}};
  return <SimpleAuth title="Choose a new password" subtitle="Use at least 8 characters and confirm it below.">{notice&&<Notice type={notice.type}>{notice.text}</Notice>}<form onSubmit={submit} className="auth-form"><Input label="Email address" type="email" required value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/><Input label="New password" type="password" required value={form.password} onChange={e=>setForm({...form,password:e.target.value})}/><Input label="Confirm password" type="password" required value={form.password_confirmation} onChange={e=>setForm({...form,password_confirmation:e.target.value})}/><Button loading={busy} className="wide">Update password</Button></form></SimpleAuth>;
}
function SimpleAuth({title,subtitle,children}){return <div className="simple-auth"><div className="login-card"><div className="mobile-brand always"><BrandLogo/></div><div className="auth-copy"><span className="eyebrow">Account security</span><h2>{title}</h2><p>{subtitle}</p></div>{children}</div></div>}
