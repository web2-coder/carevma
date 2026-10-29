import { useEffect, useState } from 'react';
import { FileText, Trash2, Upload } from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { Button, Card, Input, Notice, PageHeader, Pagination, Table, Textarea, dateTime } from '../components/ui';

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
  const [form, setForm] = useState({ title: '', notes: '' });
  const [document, setDocument] = useState(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const load = async (nextPage = page, nextSearch = search) => {
    setBusy(true);
    try {
      const { data } = await api.get('/notebook', { params: { page: nextPage, search: nextSearch, per_page: 10 } });
      setRows(data?.data || []);
      setMeta(data || null);
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
    if (!document) {
      setNotice({ type: 'error', text: 'Choose a document to upload.' });
      return;
    }
    setBusy(true);
    try {
      const payload = new FormData();
      payload.append('title', form.title || document.name);
      payload.append('notes', form.notes || '');
      payload.append('document', document);
      const { data } = await api.post('/notebook', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
      setNotice({ type: 'success', text: data.message });
      setForm({ title: '', notes: '' });
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

  const canDelete = row => row.user_id === user?.id || ['admin', 'superadmin'].includes(user?.role);
  const columns = [
    { key: 'title', label: 'Title' },
    { key: 'owner', label: 'Uploaded by', render: row => row.user?.name || '—' },
    { key: 'file', label: 'Document', render: row => <a className="btn btn-secondary btn-link" href={row.file_url} target="_blank" rel="noreferrer"><FileText size={15}/> {row.original_name}</a> },
    { key: 'size', label: 'Size', render: row => fileSize(row.file_size) },
    { key: 'notes', label: 'Notes', render: row => row.notes || '—' },
    { key: 'created_at', label: 'Uploaded', render: row => dateTime(row.created_at, user?.assigned_timezone || 'UTC') },
    { key: 'actions', label: 'Actions', render: row => canDelete(row) ? <button className="icon-btn danger" type="button" title="Delete document" onClick={() => remove(row)}><Trash2 size={16}/></button> : '—' },
  ];

  return <>
    <PageHeader eyebrow="Documents" title="Notebook" description="Upload working documents and open previously uploaded notebook files."/>
    {notice && <Notice type={notice.type} onClose={() => setNotice(null)}>{notice.text}</Notice>}
    <Card title="Upload document" subtitle="PDF, Word, Excel, CSV, TXT, JPG or PNG · max 10 MB">
      <div className="form-grid">
        <Input label="Title *" value={form.title} onChange={event => setForm(current => ({ ...current, title: event.target.value }))} placeholder="Document title"/>
        <label className="field"><span>Document *</span><input id="carevma-notebook-upload" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png" onChange={event => setDocument(event.target.files?.[0] || null)}/><small>{document?.name || 'Choose a file'}</small></label>
        <div style={{gridColumn:'1 / -1'}}><Textarea label="Notes" value={form.notes} onChange={event => setForm(current => ({ ...current, notes: event.target.value }))} placeholder="Optional notes about this document"/></div>
      </div>
      <Button icon={Upload} loading={busy} onClick={upload}>Upload to notebook</Button>
    </Card>
    <Card title="Uploaded documents" actions={<Input aria-label="Search notebook" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search documents…"/>}>
      <Table columns={columns} rows={rows} localPagination={false} emptyTitle="No notebook documents" emptyDescription="Upload the first document above."/>
      <Pagination meta={meta} onPage={setPage}/>
    </Card>
  </>;
}
