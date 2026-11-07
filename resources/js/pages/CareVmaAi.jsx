import { useEffect, useMemo, useRef, useState } from 'react';
import { Bot, Database, Send, ShieldCheck, Sparkles, UserRound } from 'lucide-react';
import api from '../api';

const EXAMPLES = [
  'Harram Laraib ki October attendance aur late records dikhao',
  'Is month ke sab invoices aur total amount batao',
  'Jo employees probation pe hain unka complete record do',
  'Harram ka sara CareVMA record dikhao',
  'Abhi kaun kaun VA working hai aur kis Doctor ke sath?',
];

const pretty = (key) => String(key || '').replaceAll('_', ' ').replace(/\b\w/g, (m) => m.toUpperCase());
const valueText = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};

function DataTable({ rows }) {
  if (!Array.isArray(rows) || !rows.length) return null;
  const columns = Array.from(new Set(rows.flatMap((row) => Object.keys(row || {}))));
  return (
    <div className="cv-ai-table-wrap">
      <table>
        <thead><tr>{columns.map((column) => <th key={column}>{pretty(column)}</th>)}</tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index}>{columns.map((column) => <td key={column}>{valueText(row?.[column])}</td>)}</tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ReportCard({ report }) {
  const meta = report?.meta || {};
  return (
    <div className="cv-ai-report-card">
      <div className="cv-ai-report-head">
        <div>
          <strong>{report?.title || 'CareVMA Records'}</strong>
          {report?.summary && <span>{report.summary}</span>}
        </div>
        {Number.isFinite(Number(meta.total_matches)) && (
          <small>{meta.returned ?? 0} / {meta.total_matches} shown</small>
        )}
      </div>
      {meta.truncated && (
        <div className="cv-ai-truncated">More records matched than are displayed. Refine the prompt if you want a smaller exact set.</div>
      )}
      <DataTable rows={report?.rows} />
      {Array.isArray(report?.sections) && report.sections.map((section, index) => (
        <div className="cv-ai-section" key={`${section?.title || 'section'}-${index}`}>
          <h4>{section?.title || 'Details'}</h4>
          {section?.summary && <p>{section.summary}</p>}
          <DataTable rows={section?.rows} />
        </div>
      ))}
    </div>
  );
}

function AssistantMessage({ item }) {
  const result = item?.result;
  return (
    <div className="cv-ai-message assistant">
      <div className="cv-ai-message-avatar"><Bot size={17} /></div>
      <div className="cv-ai-message-body">
        <div className="cv-ai-message-copy">{item?.text || 'No response returned.'}</div>
        {!result?.provider_ready && result && (
          <div className="cv-ai-provider-warning">
            Smart AI provider is not configured/reachable. Records below are still read-only CareVMA data, but language understanding is using the basic fallback parser.
          </div>
        )}
        {Array.isArray(result?.reports) && result.reports.map((report, index) => (
          <ReportCard report={report} key={`${report?.dataset || 'report'}-${index}`} />
        ))}
      </div>
    </div>
  );
}

export default function CareVmaAiPage() {
  const [prompt, setPrompt] = useState('');
  const [busy, setBusy] = useState(false);
  const [messages, setMessages] = useState([]);
  const bottomRef = useRef(null);

  useEffect(() => {
    let mounted = true;
    api.get('/carevma-ai/history', { carevmaAutoToast: false })
      .then(({ data }) => {
        if (!mounted || !Array.isArray(data?.data)) return;
        const history = [...data.data].reverse().slice(-10).flatMap((row) => {
          const pair = [{ type: 'user', text: row.prompt, historical: true }];
          if (row.assistant_message) pair.push({ type: 'assistant', text: row.assistant_message, historical: true });
          return pair;
        });
        setMessages(history);
      })
      .catch(() => {});
    return () => { mounted = false; };
  }, []);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [messages, busy]);

  const canSend = useMemo(() => Boolean(prompt.trim()) && !busy, [prompt, busy]);

  const ask = async (event) => {
    event?.preventDefault?.();
    const question = prompt.trim();
    if (!question || busy) return;

    setPrompt('');
    setBusy(true);
    setMessages((current) => [...current, { type: 'user', text: question }]);

    try {
      const { data } = await api.post('/carevma-ai/ask', { prompt: question }, { carevmaAutoToast: false });
      setMessages((current) => [...current, {
        type: 'assistant',
        text: data?.assistant_message || data?.answer?.summary || 'CareVMA report ready.',
        result: data,
      }]);
    } catch (error) {
      const message = error?.response?.data?.message
        || 'CareVMA AI could not complete this request. Please check the AI configuration or server log.';
      setMessages((current) => [...current, { type: 'assistant', text: message, error: true }]);
    } finally {
      setBusy(false);
    }
  };

  const keyDown = (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      ask(event);
    }
  };

  return (
    <div className="cv-ai-page cv-ai-chat-page">
      <div className="cv-ai-title">
        <div>
          <div className="cv-ai-logo"><Sparkles size={22} /></div>
          <div>
            <h1>CareVMA AI</h1>
            <p>Ask in English, Roman Urdu, or mixed language. AI reads only the CareVMA data you are authorized to access.</p>
          </div>
        </div>
        <span><ShieldCheck size={16} /> Read-only</span>
      </div>

      <div className="cv-ai-conversation">
        {messages.length === 0 && (
          <div className="cv-ai-welcome">
            <div className="cv-ai-welcome-icon"><Bot size={28} /></div>
            <h2>CareVMA se jo poochna hai poochain</h2>
            <p>Attendance, late time, Doctors, schedules, invoices, payroll, probation, overtime, live workforce aur employee full record — normal conversation ki tarah.</p>
            <div className="cv-ai-capabilities">
              <span><Database size={14} /> Live CareVMA records</span>
              <span><ShieldCheck size={14} /> Read-only</span>
              <span><Sparkles size={14} /> Roman Urdu + English</span>
            </div>
          </div>
        )}

        {messages.map((item, index) => item.type === 'user' ? (
          <div className="cv-ai-message user" key={index}>
            <div className="cv-ai-message-avatar"><UserRound size={17} /></div>
            <div className="cv-ai-message-body"><div className="cv-ai-message-copy">{item.text}</div></div>
          </div>
        ) : <AssistantMessage item={item} key={index} />)}

        {busy && (
          <div className="cv-ai-message assistant">
            <div className="cv-ai-message-avatar"><Bot size={17} /></div>
            <div className="cv-ai-message-body"><div className="cv-ai-thinking"><i /><i /><i /> CareVMA records check ho rahe hain…</div></div>
          </div>
        )}
        <div ref={bottomRef} />
      </div>

      <div className="cv-ai-example-row">
        {EXAMPLES.map((example) => <button type="button" key={example} onClick={() => setPrompt(example)}>{example}</button>)}
      </div>

      <form className="cv-ai-composer" onSubmit={ask}>
        <textarea
          value={prompt}
          onChange={(event) => setPrompt(event.target.value)}
          onKeyDown={keyDown}
          placeholder="e.g. Harram ki pichlay month ki sari attendance aur late time batao…"
          rows={3}
        />
        <button disabled={!canSend} type="submit"><Send size={18} />{busy ? 'Checking…' : 'Ask'}</button>
        <small>Enter to send · Shift + Enter for new line · AI cannot update or delete CareVMA data</small>
      </form>
    </div>
  );
}
