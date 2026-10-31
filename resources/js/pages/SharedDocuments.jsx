import { useEffect, useState } from 'react';
import { FileText } from 'lucide-react';
import api, { messageFrom } from '../api';
import { useAuth } from '../context/AuthContext';
import { Card, Input, Notice, PageHeader, Pagination, Table, dateTime } from '../components/ui';

function fileSize(bytes) {
  const value = Math.max(0, Number(bytes) || 0);
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

export default function SharedDocumentsPage() {
  const { user } = useAuth();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [notice, setNotice] = useState(null);

  const load = async (nextPage = page, nextSearch = search) => {
    try {
      const { data } = await api.get('/shared-documents', { params: { page: nextPage, search: nextSearch, per_page: 10 } });
      setRows(data?.data || []);
      setMeta(data || null);
    } catch (error) {
      setNotice({ type: 'error', text: messageFrom(error) });
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

  const columns = [
    { key: 'title', label: 'Title' },
    { key: 'shared_by', label: 'Shared by', render: row => row.user?.name || 'CareVMA' },
    { key: 'file', label: 'Document', render: row => <a className="btn btn-secondary btn-link" href={row.file_url} target="_blank" rel="noreferrer"><FileText size={15}/> {row.original_name}</a> },
    { key: 'size', label: 'Size', render: row => fileSize(row.file_size) },
    { key: 'notes', label: 'Notes', render: row => row.notes || '—' },
    { key: 'shared_at', label: 'Shared', render: row => dateTime(row.shared_at || row.created_at, user?.assigned_timezone || 'UTC') },
  ];

  return <>
    <PageHeader eyebrow="Documents" title="Shared Documents" description="Only documents specifically shared with your account are visible here."/>
    {notice && <Notice type={notice.type} onClose={() => setNotice(null)}>{notice.text}</Notice>}
    <Card title="Documents shared with me" actions={<Input aria-label="Search documents" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search documents…"/>}>
      <Table columns={columns} rows={rows} localPagination={false} emptyTitle="No shared documents" emptyDescription="Admin or HR has not shared a document with your account yet."/>
      <Pagination meta={meta} onPage={setPage}/>
    </Card>
  </>;
}
