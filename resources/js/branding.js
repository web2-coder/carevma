import api from './api';

export const COLOR_KEYS=['primary','secondary','accent','background','text','sidebar','navbar','button','success','warning','error','info','surface','border','muted','sidebar_text','sidebar_active','sidebar_active_text','navbar_text','button_text','input_background'];
export const DEFAULT_THEME={mode:'light',font_family:'Poppins',spacing:'comfortable',radius:'comfortable',palettes:{light:{primary:'#0E3180',secondary:'#163B49',accent:'#2763B8',background:'#FFFFFF',text:'#111111',sidebar:'#FFFFFF',navbar:'#FFFFFF',button:'#0E3180',success:'#16855B',warning:'#C17A15',error:'#C94949',info:'#237DA9',surface:'#FFFFFF',border:'#DCE7F3',muted:'#61748D',sidebar_text:'#111111',sidebar_active:'#E8F0FF',sidebar_active_text:'#111111',navbar_text:'#111111',button_text:'#FFFFFF',input_background:'#FFFFFF'},dark:{primary:'#47C6AA',secondary:'#82ABBC',accent:'#9FCC3A',background:'#0B1720',text:'#EDF5F5',sidebar:'#07121B',navbar:'#12232D',button:'#287F70',success:'#3BC78A',warning:'#F5B94B',error:'#FA7373',info:'#67BDEB',surface:'#152832',border:'#334653',muted:'#B3C3CF',sidebar_text:'#EDF5FA',sidebar_active:'#255E76',sidebar_active_text:'#FFFFFF',navbar_text:'#EDF5F5',button_text:'#FFFFFF',input_background:'#12232D'}}};
const storageKey='carevma:white-label:theme';
let companyTheme=null;
let personalMode=null;
export function activeThemeMode(){return document.documentElement.dataset.themeMode==='dark'?'dark':'light'}
export function setPersonalThemeMode(mode){
  personalMode=mode==='dark'?'dark':mode==='light'?'light':null;
  applyTheme(companyTheme||getCachedTheme()||DEFAULT_THEME,{cache:false});
}
export function cachePersonalMode(userId,mode){
  if(!userId)return;
  try{localStorage.setItem('carevma:user:'+userId+':theme-mode',mode)}catch{}
}
export function cachedPersonalMode(userId){
  if(!userId)return null;
  try{const value=localStorage.getItem('carevma:user:'+userId+':theme-mode');return value==='dark'||value==='light'?value:null}catch{return null}
}
export function normalizeTheme(theme){ const initial=JSON.parse(JSON.stringify(DEFAULT_THEME)); if(!theme||typeof theme!=='object')return initial; return {...initial,...theme,palettes:{light:{...initial.palettes.light,...theme.palettes?.light},dark:{...initial.palettes.dark,...theme.palettes?.dark}}}; }
export function applyTheme(theme,{cache=true}={}){
  theme=normalizeTheme(theme);
  if(cache)companyTheme=theme;
  const effectiveMode=personalMode||theme.mode;
  const root=document.documentElement, colors=theme.palettes[effectiveMode]||theme.palettes.light||{};
  // CSS design tokens use kebab-case. Preset palette keys use snake_case.
  // Without the conversion, sidebar_text did not reach --wl-sidebar-text,
  // leaving dark default labels on colored sidebars.
  for(const k of COLOR_KEYS){
    if(/^#[\da-f]{6}$/i.test(colors[k]||''))root.style.setProperty('--wl-'+k.replaceAll('_','-'),colors[k]);
  }
  // Migration-safe contrast for themes saved before the token mapping fix.
  // Original White is the only white-sidebar preset; colored presets always
  // have white labels and icons, including active and hover states.
  const whiteSidebar=String(colors.sidebar||'').toUpperCase()==='#FFFFFF' && effectiveMode!=='dark';
  root.style.setProperty('--wl-sidebar-text',whiteSidebar?'#111111':'#FFFFFF');
  root.style.setProperty('--wl-sidebar-active-text',whiteSidebar?'#111111':'#FFFFFF');
  root.style.setProperty('--wl-sidebar-hover', colors.sidebar_active);
  root.dataset.themeMode=effectiveMode==='dark'?'dark':'light';
  root.style.setProperty('--wl-radius',({compact:'8px',comfortable:'14px',spacious:'22px'})[theme.radius]||'14px');
  root.style.setProperty('--wl-spacing',({compact:'.8',comfortable:'1',spacious:'1.25'})[theme.spacing]||'1');
  root.style.setProperty('--wl-button-text', colors.button_text||'#FFFFFF');
  const font=theme.font_family==='Custom'?'CareVMA Custom':theme.font_family||'Poppins';
  if(/^[\w -]{1,45}$/.test(font))root.style.setProperty('--wl-font',`"${font}",system-ui,sans-serif`);
  if(theme.font_family!=='Custom'&&font!=='Poppins'){
    let link=document.getElementById('wl-font-link');if(!link){link=document.createElement('link');link.id='wl-font-link';link.rel='stylesheet';document.head.appendChild(link)}
    link.href='https://fonts.googleapis.com/css2?family='+encodeURIComponent(font).replace(/%20/g,'+')+':wght@400;500;600;700&display=swap';
  }
  if(cache){try{localStorage.setItem(storageKey,JSON.stringify(theme));}catch{}}
  window.dispatchEvent(new Event('carevma:theme-changed'));
}
export function getCachedTheme(){try{return JSON.parse(localStorage.getItem(storageKey)||'null')}catch{return null}}
export function publishBrand(branding){
  window.__carevmaBrand=branding||{};
  window.dispatchEvent(new Event('carevma:brand-changed'));
  const favicon=branding?.favicon;if(favicon){document.querySelectorAll('link[rel="icon"],link[rel="shortcut icon"]').forEach(node=>node.href=favicon)}
  if(branding?.company_name)document.title=branding.company_name;
  const hero=document.querySelector('.auth-hero-image');if(hero&&branding?.login_background)hero.src=branding.login_background;
  const custom=branding?.custom_font;let el=document.getElementById('wl-custom-font');
  if(custom&&/^\/storage\/[\w./-]+$/.test(custom)){if(!el){el=document.createElement('style');el.id='wl-custom-font';document.head.appendChild(el)}el.textContent=`@font-face{font-family:"CareVMA Custom";src:url("${custom}");font-display:swap}`}
}
export async function loadBranding(){try{const {data}=await api.get('/settings/public',{carevmaPageLoader:false});if(data.theme)applyTheme(data.theme);publishBrand(data.branding||{});return data}catch{return null}}
