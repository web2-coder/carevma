import { useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import api, { messageFrom } from '../api';
import { Input } from './ui';
import { DEFAULT_TIMEZONE, formatDateTime } from '../timezone';

export default function SalaryConverter({ pkr, usd, onPkrChange, onUsdChange }) {
  const [fx, setFx] = useState({ loading: true, rate: null, source: null, updated_at: null, error: null });
  const [pkrChanged, setPkrChanged] = useState(false);

  const loadRate = async () => {
    setFx(current => ({ ...current, loading: true, error: null }));
    try {
      const { data } = await api.get('/management/fx/pkr-usd');
      setFx({ loading: false, rate: Number(data.rate), source: data.source, updated_at: data.updated_at, error: null });
    } catch (error) {
      setFx({ loading: false, rate: null, source: null, updated_at: null, error: messageFrom(error) });
    }
  };

  useEffect(() => { loadRate(); }, []);
  useEffect(() => {
    if (!pkrChanged || !fx.rate || pkr === '') return;
    const amount = Number(pkr);
    if (Number.isFinite(amount)) onUsdChange((amount * fx.rate).toFixed(2));
  }, [fx.rate]);

  const changePkr = value => {
    setPkrChanged(true);
    onPkrChange(value);
    if (fx.rate && value !== '') {
      const amount = Number(value);
      onUsdChange(Number.isFinite(amount) ? (amount * fx.rate).toFixed(2) : '');
    } else if (value === '') {
      onUsdChange('');
    }
  };

  return <div className="salary-converter">
    <Input label="Salary PKR" type="number" min="0" step="1" value={pkr || ''} onChange={e => changePkr(e.target.value)} hint="Type PKR salary; USD updates automatically."/>
    <Input label="Salary USD" type="number" min="0" step="0.01" value={usd || ''} readOnly={!!fx.rate} onChange={e => onUsdChange(e.target.value)} hint={fx.rate ? 'Auto-converted from PKR using the cached live rate.' : 'FX unavailable: enter USD manually.'}/>
    <div className={`salary-fx-note ${fx.error ? 'error' : ''}`}>
      <div>
        {fx.loading && <span>Loading PKR → USD rate…</span>}
        {!fx.loading && fx.rate && <span><strong>1 PKR = {fx.rate.toFixed(6)} USD</strong>{fx.updated_at ? ` · updated ${formatDateTime(fx.updated_at, DEFAULT_TIMEZONE)}` : ''}</span>}
        {!fx.loading && fx.error && <span>{fx.error}</span>}
        <a href="https://www.exchangerate-api.com" target="_blank" rel="noreferrer">Rates by ExchangeRate-API</a>
      </div>
      <button type="button" className="icon-refresh" onClick={loadRate} disabled={fx.loading} title="Refresh exchange rate"><RefreshCw size={15} className={fx.loading ? 'spin' : ''}/></button>
    </div>
  </div>;
}
