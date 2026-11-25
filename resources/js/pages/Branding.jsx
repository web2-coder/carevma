import { useEffect, useRef, useState } from 'react';
import { useCan } from '../permissions';
import api,{messageFrom} from '../api';
import { applyTheme, normalizeTheme, publishBrand, DEFAULT_THEME } from '../branding';
const FONTS=['Poppins','Inter','Roboto','Open Sans','Lato','Montserrat','Nunito','Source Sans 3','Custom'];
const copy=x=>JSON.parse(JSON.stringify(x));
const readableText=hex=>{const n=parseInt(String(hex).replace('#',''),16);if(!Number.isFinite(n))return '#FFFFFF';const channels=[(n>>16)&255,(n>>8)&255,n&255].map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});return channels[0]*.2126+channels[1]*.7152+channels[2]*.0722>.179?'#10232C':'#FFFFFF'};
export default function BrandingPage(){
  const allowed=useCan('branding');
  const editable=allowed('edit');
  const [tab,setTab]=useState('theme'),[theme,setTheme]=useState(copy(DEFAULT_THEME)),[original,setOriginal]=useState(copy(DEFAULT_THEME)),[brand,setBrand]=useState({}),[savedBrand,setSavedBrand]=useState({}),[busy,setBusy]=useState(false),[notice,setNotice]=useState(''),[error,setError]=useState(''),[localPreview,setLocalPreview]=useState({});
  const savedThemeRef=useRef(null);
  const flash=(message,bad=false)=>{setNotice(bad?'':message);setError(bad?message:'')};
  useEffect(()=>{Promise.all([api.get('/settings/theme'),api.get('/settings/branding')]).then(([t,b])=>{const next=normalizeTheme(t.data);setTheme(copy(next));setOriginal(copy(next));savedThemeRef.current=copy(next);setBrand(b.data);setSavedBrand(copy(b.data))}).catch(e=>flash(messageFrom(e),true))},[]);
  // Editing a preset updates the isolated preview only. Never change the live portal until Save.
  const saveTheme=async()=>{setBusy(true);try{const {data}=await api.put('/settings/theme',theme);setTheme(data);setOriginal(copy(data));savedThemeRef.current=copy(data);applyTheme(data);flash('Theme applied and saved successfully')}catch(e){flash(messageFrom(e),true)}finally{setBusy(false)}};
  const resetTheme=async()=>{if(!confirm('Reset theme colors and appearance to defaults?'))return;setBusy(true);try{const {data}=await api.post('/settings/theme/reset');setTheme(data);setOriginal(copy(data));savedThemeRef.current=copy(data);applyTheme(data);flash('Original White light theme restored')}catch(e){flash(messageFrom(e),true)}finally{setBusy(false)}};
  const cancelTheme=()=>{setTheme(copy(original));flash('Unsaved changes discarded')};
  const saveBrand=async()=>{setBusy(true);try{const {data}=await api.put('/settings/branding',{company_name:brand.company_name,tagline:brand.tagline,support_email:brand.support_email,support_phone:brand.support_phone,footer_text:brand.footer_text});setBrand(data);setSavedBrand(copy(data));publishBrand(data);flash('Branding saved')}catch(e){flash(messageFrom(e),true)}finally{setBusy(false)}};
  const upload=async(file)=>{
    if(!editable||!file||busy)return;
    if(file.size>4*1024*1024){flash('Logo must be smaller than 4 MB.',true);return;}
    if(!/^image\/(png|jpeg|webp)$/.test(file.type)){flash('Use PNG, JPG or WebP for the main logo.',true);return;}
    setBusy(true);
    try{
      const fd=new FormData();fd.append('file',file);fd.append('kind','logo_light');
      const {data}=await api.post('/settings/upload-logo',fd);
      const next={...brand,logo_light:data.url,logo_dark:'',logo_collapsed:''};
      setBrand(next);setSavedBrand(copy(next));publishBrand(next);
      flash(data.warning||'Main logo updated across the portal.');
    }catch(e){flash(messageFrom(e),true)}finally{setBusy(false)}
  };
  const removeLogo=async()=>{
    if(busy||!brand.logo_light||!window.confirm('Remove main logo and restore the default CareVMA logo?'))return;
    setBusy(true);
    try{
      const {data}=await api.delete('/settings/logo');
      setBrand(data);setSavedBrand(copy(data));publishBrand(data);
      flash('Main logo removed. Default CareVMA logo restored.');
    }catch(e){flash(messageFrom(e),true)}finally{setBusy(false)}
  };
  // The first option restores the original CareVMA palette without changing typography/layout.
  const presets=[
    {id:'classic',name:'CareVMA Classic',primary:'#0B1F2A',accent:'#21A38B',original:true},
    {id:'original-white',name:'Original White',primary:'#FFFFFF',accent:'#2763B8',white:true,default:true},
    {id:'forest',name:'Forest',primary:'#0C3A30',accent:'#9FCC3A'},
    {id:'ocean',name:'Ocean',primary:'#124F91',accent:'#21B8CD'},
    {id:'plum',name:'Plum',primary:'#602B7A',accent:'#D86BC4'},
    {id:'royal',name:'Royal Blue',primary:'#0E3180',accent:'#4D91FF'},
    {id:'indigo',name:'Indigo',primary:'#343A8B',accent:'#9D8DF1'},
    {id:'teal',name:'Teal',primary:'#076A70',accent:'#39D1BB'},
    {id:'ruby',name:'Ruby',primary:'#882C46',accent:'#ED7794'},
    {id:'sunset',name:'Sunset',primary:'#8A422F',accent:'#F6A660'},
    {id:'slate',name:'Slate',primary:'#334155',accent:'#38BDF8'},
    {id:'olive',name:'Olive',primary:'#46582D',accent:'#B8CF65'},
    {id:'midnight',name:'Midnight',primary:'#16213E',accent:'#6C98DF'},
  ];
  const makePalette=(preset,mode)=>{
    if(preset.original){const base=copy(DEFAULT_THEME.palettes[mode]); if(mode==='dark')return {...base,sidebar_text:'#FFFFFF',sidebar_active_text:'#FFFFFF'}; return {...base,primary:'#0B1F2A',secondary:'#163B49',accent:'#21A38B',background:'#F4F7F9',text:'#10232C',sidebar:'#0B1F2A',sidebar_text:'#ECF3FF',sidebar_active:'#2763B8',sidebar_active_text:'#FFFFFF',navbar:'#FFFFFF',navbar_text:'#10232C',button:'#0B1F2A'};}
    if(preset.white){
      const base=copy(DEFAULT_THEME.palettes[mode]);
      if(mode==='dark')return {...base,sidebar_text:'#FFFFFF',sidebar_active_text:'#FFFFFF'};
      return {...base,primary:'#0E3180',secondary:'#163B49',accent:'#2763B8',sidebar:'#FFFFFF',sidebar_text:'#111111',sidebar_active:'#E8F0FF',sidebar_active_text:'#111111',navbar:'#FFFFFF',navbar_text:'#111111',button:'#0E3180',button_text:'#FFFFFF',background:'#FFFFFF',surface:'#FFFFFF',input_background:'#FFFFFF',border:'#DCE7F3',text:'#111111',muted:'#61748D'};
    }
    const dark=mode==='dark';
    const base=copy(DEFAULT_THEME.palettes[mode]);
    const primary=preset.primary,accent=preset.accent;
    const sidebar=dark?'#101B2D':primary;
    const button=dark?accent:primary;
    const active=dark?primary:accent;
    return {...base,primary:button,secondary:primary,accent,sidebar,
      sidebar_text:'#FFFFFF',sidebar_active:active,
      sidebar_active_text:'#FFFFFF',navbar:dark?'#162338':'#FFFFFF',
      navbar_text:dark?'#EDF5F5':'#10232C',button,button_text:readableText(button),
      background:dark?'#0B1720':'#F4F7F9',surface:dark?'#152832':'#FFFFFF',
      input_background:dark?'#12232D':'#FFFFFF',border:dark?'#334653':'#DCE7F3',
      text:dark?'#EDF5F5':'#10232C',muted:dark?'#B3C3CF':'#61748D'};
  };
  const selectPreset=preset=>setTheme(current=>({
    ...current,mode:'light',
    palettes:{light:makePalette(preset,'light'),dark:makePalette(preset,'dark')}
  }));
  const previewColors=theme.palettes?.[theme.mode]||DEFAULT_THEME.palettes.light;
  const previewStyle=Object.fromEntries(Object.entries(previewColors).map(([key,value])=>['--wl-'+key.replaceAll('_','-'),value]));
  const activePreset=presets.find(p=>['light','dark'].every(mode=>{
    const current=theme.palettes?.[mode]||{};
    const expected=makePalette(p,mode);
    return ['primary','accent','sidebar','sidebar_active','button','background','navbar'].every(key=>current[key]?.toUpperCase()===expected[key]?.toUpperCase());
  }));
  return <div className="wl-page"><div><h1>Branding & Appearance</h1><p>Customize your portal's actual colors, logo and company identity. Preview first, then save when ready.</p></div>
    <div className="wl-tabs">{[['theme','Colors & appearance'],['brand','Logos & company']].map(([id,label])=><button key={id} className={tab===id?'active':''} onClick={()=>{setTab(id);setNotice('');setError('')}}>{label}</button>)}</div>
    {notice&&<div role="status" className="wl-ok">{notice}</div>}{error&&<div role="alert" className="wl-error">{error}</div>}
    {tab==='theme'&&<section className="wl-section"><h3>Live theme preview</h3><p className="wl-note">Original White is the default on a fresh install, with dark text on the light sidebar and top navbar. Colored presets always use white sidebar labels, icons and active-menu text. Selecting another preset only changes this preview; the portal changes after you click Apply theme.</p><div className="wl-grid"><label className="wl-field">Mode<select disabled={!editable} value={theme.mode} onChange={e=>setTheme({...theme,mode:e.target.value})}><option value="light">Light</option><option value="dark">Dark</option></select></label><label className="wl-field">Font family<select disabled={!editable} value={theme.font_family} onChange={e=>setTheme({...theme,font_family:e.target.value})}>{FONTS.map(f=><option key={f}>{f}</option>)}</select></label><label className="wl-field">Spacing<select disabled={!editable} value={theme.spacing} onChange={e=>setTheme({...theme,spacing:e.target.value})}>{['compact','comfortable','spacious'].map(f=><option key={f}>{f}</option>)}</select></label><label className="wl-field">Border radius<select disabled={!editable} value={theme.radius} onChange={e=>setTheme({...theme,radius:e.target.value})}>{['compact','comfortable','spacious'].map(f=><option key={f}>{f}</option>)}</select></label></div><div className="wl-preview-card" style={previewStyle}><div className="wl-preview-side">Sidebar <span>Dashboard</span><strong>Active menu</strong></div><div className="wl-preview-body"><div className="wl-preview-top">Navbar · {brand.company_name||'CareVMA'}</div><div className="wl-preview-surface"><b>Dashboard card</b><p>Sample text and borders update instantly.</p><button type="button">Primary button</button></div></div></div>    <h4>Preset palettes</h4>
    <p className="wl-note">Choose one complete color scheme. It updates the sidebar, active menu, navbar, buttons and page surfaces in both light and dark modes. Only the preview changes until you click Apply theme.</p>
    <div className="wl-preset wl-preset-grid" role="group" aria-label="Color scheme presets">{presets.map(p=><button type="button" key={p.id} className={`wl-preset-choice${activePreset?.id===p.id?' is-selected':''}`} aria-pressed={activePreset?.id===p.id} disabled={!editable} onClick={()=>selectPreset(p)}>
      <span className="wl-preset-swatches" aria-hidden="true"><i style={{background:p.primary}}/><i style={{background:p.accent}}/><i style={{background:p.white?'#FFFFFF':'#F4F7F9'}}/></span>
      <span className="wl-preset-title">{p.name}</span>
      {p.original&&<span className="wl-preset-default">Classic</span>}{p.white&&<span className="wl-preset-default">Default · White BG</span>}
      {activePreset?.id===p.id&&<span className="wl-preset-check" aria-hidden="true">✓</span>}
    </button>)}</div>
    {editable&&<div className="wl-actions"><button disabled={busy} onClick={saveTheme}>Apply theme</button><button className="secondary" onClick={cancelTheme}>Cancel</button><button className="secondary" disabled={busy} onClick={resetTheme}>Reset defaults</button></div>}</section>}
    {tab==='brand'&&<><section className="wl-section"><h3>Company identity</h3><div className="wl-grid">{[['company_name','Company name'],['tagline','Tagline'],['support_email','Support email'],['support_phone','Support phone'],['footer_text','Footer / copyright']].map(([key,label])=><label key={key} className="wl-field">{label}<input disabled={!editable} type={key==='support_email'?'email':'text'} maxLength={key==='company_name'?120:key==='tagline'?180:key==='support_email'?190:key==='support_phone'?40:240} value={brand[key]||''} onChange={e=>setBrand(b=>({...b,[key]:e.target.value}))}/></label>)}</div>{editable&&<div className="wl-actions"><button disabled={busy} onClick={saveBrand}>Save branding</button><button className="secondary" onClick={()=>{setBrand(copy(savedBrand));publishBrand(savedBrand)}}>Cancel</button></div>}</section><section className="wl-section"><h3>Main logo</h3><p className="wl-note">One logo for the sidebar, navbar and login page. Displays at 150px width, automatic height. Recommended image width: 300px or more. PNG, JPG or WebP, max 4 MB.</p>
    <div className="wl-main-logo-editor"><div className="wl-main-logo-display">{brand.logo_light?<img className="wl-logo-preview" src={brand.logo_light} alt="Current main logo" onError={e=>{e.currentTarget.hidden=true}}/>:<div className="wl-logo-empty">Default CareVMA logo</div>}</div>
    <div className="wl-main-logo-controls">{editable&&<label className="wl-upload wl-single-upload" onDragOver={e=>e.preventDefault()} onDrop={e=>{e.preventDefault();upload(e.dataTransfer.files?.[0])}}><strong>{brand.logo_light?'Replace main logo':'Upload main logo'}</strong><span>Drop an image here or select a file.</span><input type="file" accept="image/png,image/jpeg,image/webp" disabled={busy} onChange={e=>{upload(e.target.files?.[0]);e.target.value=''}}/></label>}
    {allowed('delete')&&brand.logo_light&&<button type="button" className="wl-remove-logo" disabled={busy} onClick={removeLogo}>Remove logo</button>}</div></div>
    </section></>}
  </div>;
}
