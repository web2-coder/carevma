import { useEffect, useMemo, useState } from 'react';
import { FileText, Send, Trash2 } from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { Button, Card, Input, Notice, PageHeader, Pagination, SearchableSelect, Table, Textarea, dateTime } from '../components/ui';

function fileSize(bytes) {
  const value = Math.max(0, Number(bytes) || 0);
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

export default function NotebookPage() {
  const { user } = useAuth();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState({ employees: [], clients: [] });
  const [form, setForm] = useState({ title: '', notes: '', recipient_user_id: '', recipient_type: '' });
  const [document, setDocument] = useState(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const vaRecipients = useMemo(() => (options.employees || [])
    .filter(row => row.user_id)
    .map(row => ({
      value: String(row.user_id),
      label: `${row.employee_code ? `${row.employee_code} · ` : ''}${row.first_name || ''} ${row.last_name || ''}`.trim(),
      meta: row.timezone || 'UTC',
      timezone: row.timezone || 'UTC',
    }))
    .sort((a, b) => a.label.localeCompare(b.label)), [options]);

  const doctorRecipients = useMemo(() => (options.clients || [])
    .filter(row => row.user_id)
    .map(row => ({
      value: String(row.user_id),
      label: row.name || row.business_name || row.email,
      meta: row.timezone || 'UTC',
      timezone: row.timezone || 'UTC',
    }))
    .sort((a, b) => a.label.localeCompare(b.label)), [options]);


  const selectedRecipient = [...vaRecipients, ...doctorRecipients].find(row => String(row.value) === String(form.recipient_user_id));

  const load = async (nextPage = page, nextSearch = search) => {
    setBusy(true);
    try {
      const [documentsResponse, optionsResponse] = await Promise.all([
        api.get('/notebook', { params: { page: nextPage, search: nextSearch, per_page: 10 } }),
        api.get('/management/options'),
      ]);
      setRows(documentsResponse.data?.data || []);
      setMeta(documentsResponse.data || null);
      setOptions(optionsResponse.data || {});
    } catch (error) {
      setNotice({ type: 'error', text: messageFrom(error) });
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => { load(page, search); }, [page]);
  useEffect(() => {
    const timer = setTimeout(() => {
      setPage(1);
      load(1, search);
    }, 250);
    return () => clearTimeout(timer);
  }, [search]);

  const upload = async () => {
    if (!form.recipient_user_id) {
      setNotice({ type: 'error', text: 'Select one VA or one Doctor who should receive this document.' });
      return;
    }
    if (!document) {
      setNotice({ type: 'error', text: 'Choose a document to upload.' });
      return;
    }

    setBusy(true);
    try {
      const payload = new FormData();
      payload.append('recipient_user_id', form.recipient_user_id);
      payload.append('title', form.title || document.name);
      payload.append('notes', form.notes || '');
      payload.append('document', document);
      const { data } = await api.post('/notebook', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
      setNotice({ type: 'success', text: data.message });
      setForm({ title: '', notes: '', recipient_user_id: '', recipient_type: '' });
      setDocument(null);
      const input = window.document.getElementById('carevma-notebook-upload');
      if (input) input.value = '';
      setPage(1);
      await load(1, search);
    } catch (error) {
      setNotice({ type: 'error', text: messageFrom(error) });
    } finally {
      setBusy(false);
    }
  };

  const remove = async row => {
    try {
      await api.delete(`/notebook/${row.id}`);
      setNotice({ type: 'success', text: 'Notebook document deleted.' });
      await load(page, search);
    } catch (error) {
      setNotice({ type: 'error', text: messageFrom(error) });
    }
  };

  const columns = [
    { key: 'title', label: 'Title' },
    { key: 'recipient', label: 'Shared with', render: row => row.recipient ? `${row.recipient.role === 'client' ? 'Doctor' : 'VA'} · ${row.recipient.name || row.recipient.email}` : 'Admin / HR only' },
    { key: 'owner', label: 'Shared by', render: row => row.user?.name || '—' },
    { key: 'file', label: 'Document', render: row => <a className="btn btn-secondary btn-link" href={row.file_url} target="_blank" rel="noreferrer"><FileText size={15}/> {row.original_name}</a> },
    { key: 'size', label: 'Size', render: row => fileSize(row.file_size) },
    { key: 'notes', label: 'Notes', render: row => row.notes || '—' },
    { key: 'created_at', label: 'Shared', render: row => dateTime(row.shared_at || row.created_at, user?.assigned_timezone || 'UTC') },
    { key: 'actions', label: 'Actions', render: row => <button className="icon-btn danger" type="button" title="Delete document" onClick={() => remove(row)}><Trash2 size={16}/></button> },
  ];

  return <>
    <PageHeader eyebrow="Admin / HR documents" title="Notebook" description="Notebook is restricted to Admin and HR. Share each document with one specific VA or Doctor account. VA and Doctor selectors are separated and searchable."/>
    {notice && <Notice type={notice.type} onClose={() => setNotice(null)}>{notice.text}</Notice>}
    <Card title="Share a document" subtitle="The selected recipient is the only VA/Doctor account that can download it. PDF, Word, Excel, CSV, TXT, JPG or PNG · max 10 MB">
      <div className="form-grid">
        <SearchableSelect
          label="Send to VA"
          value={form.recipient_type === 'va' ? form.recipient_user_id : ''}
          onChange={value => setForm(current => ({ ...current, recipient_user_id: value, recipient_type: value ? 'va' : '' }))}
          options={vaRecipients}
          placeholder="Search and select a VA"
          searchPlaceholder="Search VA name, code or timezone…"
        />
        <SearchableSelect
          label="Send to Doctor"
          value={form.recipient_type === 'doctor' ? form.recipient_user_id : ''}
          onChange={value => setForm(current => ({ ...current, recipient_user_id: value, recipient_type: value ? 'doctor' : '' }))}
          options={doctorRecipients}
          placeholder="Search and select a Doctor"
          searchPlaceholder="Search Doctor name or timezone…"
        />
        <Input label="Recipient timezone" value={selectedRecipient?.timezone || 'Select one VA or Doctor'} disabled/>
        <Input label="Title *" value={form.title} onChange={event => setForm(current => ({ ...current, title: event.target.value }))} placeholder="Document title"/>
        <label className="field"><span>Document *</span><input id="carevma-notebook-upload" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png" onChange={event => setDocument(event.target.files?.[0] || null)}/><small>{document?.name || 'Choose a file'}</small></label>
        <div style={{gridColumn:'1 / -1'}}><Textarea label="Notes" value={form.notes} onChange={event => setForm(current => ({ ...current, notes: event.target.value }))} placeholder="Optional message or notes for the recipient"/></div>
      </div>
      <Button icon={Send} loading={busy} onClick={upload}>Share document</Button>
    </Card>
    <Card title="Shared documents" actions={<Input aria-label="Search notebook" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search documents or recipients…"/>}>
      <Table columns={columns} rows={rows} localPagination={false} emptyTitle="No shared documents" emptyDescription="Share the first document above."/>
      <Pagination meta={meta} onPage={setPage}/>
    </Card>
  </>;
}
