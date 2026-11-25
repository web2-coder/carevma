import { useEffect, useState } from 'react';
export const CAREVMA_LOGO_URL = '/Care_VMA.webp';
export const CAREVMA_FAVICON_URL = '/Care_VMA-favicon.png';
export default function BrandLogo({ compact = false, className = '' }) {
  const [branding,setBranding]=useState(()=>window.__carevmaBrand||{});
  const [mode,setMode]=useState(()=>document.documentElement.dataset.themeMode||'light');
  useEffect(()=>{const update=()=>{setBranding(window.__carevmaBrand||{});setMode(document.documentElement.dataset.themeMode||'light')};window.addEventListener('carevma:brand-changed',update);window.addEventListener('carevma:theme-changed',update);return()=>{window.removeEventListener('carevma:brand-changed',update);window.removeEventListener('carevma:theme-changed',update)}},[]);
  const src=branding.logo_light||CAREVMA_LOGO_URL;
  return <span className={`carevma-brand-logo ${compact?'is-compact':''} ${className}`.trim()}><img className="brand-logo" src={src} alt={branding.company_name||'CareVMA'} onError={e=>{if(e.currentTarget.src!==new URL(CAREVMA_LOGO_URL,location.href).href)e.currentTarget.src=CAREVMA_LOGO_URL}}/></span>;
}
