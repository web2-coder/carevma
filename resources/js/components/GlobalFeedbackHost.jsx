import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';

export default function GlobalFeedbackHost() {
  const [confirm, setConfirm] = useState(null);
  const [toasts, setToasts] = useState([]);
  const lastToast = useRef({ message: '', at: 0 });

  useEffect(() => {
    const onConfirm = event => setConfirm(event.detail || null);
    const onToast = event => {
      const message = String(event.detail?.message || '').trim();
      if (!message) return;
      const now = Date.now();
      if (lastToast.current.message === message && now - lastToast.current.at < 1200) return;
      lastToast.current = { message, at: now };
      const id = `${now}-${Math.random().toString(36).slice(2)}`;
      setToasts(current => [...current.slice(-3), { id, message, type: event.detail?.type || 'success' }]);
      window.setTimeout(() => setToasts(current => current.filter(item => item.id !== id)), 4500);
    };
    window.addEventListener('carevma:confirm-request', onConfirm);
    window.addEventListener('carevma:toast', onToast);
    return () => {
      window.removeEventListener('carevma:confirm-request', onConfirm);
      window.removeEventListener('carevma:toast', onToast);
    };
  }, []);

  const resolveConfirm = confirmed => {
    if (!confirm?.id) return;
    window.dispatchEvent(new CustomEvent('carevma:confirm-result', { detail: { id: confirm.id, confirmed } }));
    setConfirm(null);
  };

  const iconFor = type => type === 'error' ? <XCircle size={18}/> : type === 'warning' ? <AlertTriangle size={18}/> : type === 'info' ? <Info size={18}/> : <CheckCircle2 size={18}/>;

  return <>
    {confirm && <div className="carevma-confirm-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && resolveConfirm(false)}>
      <div className="carevma-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="carevma-confirm-title">
        <div className={`carevma-confirm-icon ${confirm.danger ? 'danger' : ''}`}><AlertTriangle size={24}/></div>
        <div className="carevma-confirm-copy">
          <h2 id="carevma-confirm-title">{confirm.title}</h2>
          <p>{confirm.message}</p>
        </div>
        <div className="carevma-confirm-actions">
          <button type="button" className="btn btn-ghost" onClick={() => resolveConfirm(false)}>{confirm.cancelLabel || 'Cancel'}</button>
          <button type="button" className={`btn ${confirm.danger ? 'btn-danger' : 'btn-primary'}`} onClick={() => resolveConfirm(true)}>{confirm.confirmLabel || 'Confirm'}</button>
        </div>
      </div>
    </div>}

    <div className="carevma-toast-stack" aria-live="polite">
      {toasts.map(toast => <div className={`carevma-toast carevma-toast-${toast.type}`} key={toast.id}>
        {iconFor(toast.type)}<span>{toast.message}</span>
        <button type="button" aria-label="Dismiss" onClick={() => setToasts(current => current.filter(item => item.id !== toast.id))}><X size={15}/></button>
      </div>)}
    </div>
  </>;
}
