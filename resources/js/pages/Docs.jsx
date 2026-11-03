import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ArrowUpRight } from 'lucide-react';
import { PageHeader, Card, SearchBox, Badge, EmptyState } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { can, ADMIN_ROLES } from '../permissions';
import { DOCS } from '../docs';

export default function DocsPage() {
  const { user } = useAuth();
  const { hash } = useLocation();
  const [query, setQuery] = useState('');
  const admin = ADMIN_ROLES.includes(user?.role);
  const base = admin ? '/admin' : '/hr';

  // Only the modules this viewer can open are documented for them.
  const sections = useMemo(() => {
    const q = query.trim().toLowerCase();
    return DOCS
      .filter(d => (d.adminOnly ? admin : true) && (!d.module || can(user, d.module)))
      .filter(d => !q || [d.title, d.summary, d.who, ...(d.steps || []), ...(d.rules || [])].join(' ').toLowerCase().includes(q));
  }, [user, admin, query]);

  useEffect(() => {
    if (hash) document.getElementById(hash.slice(1))?.scrollIntoView({ block: 'start' });
  }, [hash, sections.length]);

  const groups = [...new Set(sections.map(s => s.group))];
  const active = hash.slice(1);

  return <>
    <PageHeader eyebrow="Help" title="Docs" description="How each module works, what the rules are and who can use it. Only the modules you have access to are listed."
      actions={<SearchBox value={query} onChange={setQuery} placeholder="Search the docs…"/>}/>
    <div className="docs-layout">
      <nav className="docs-toc" aria-label="Docs sections">
        {groups.map(group => <div key={group}>
          <div className="docs-toc-group">{group}</div>
          {sections.filter(s => s.group === group).map(s => <a key={s.key} href={`#${s.key}`} className={active === s.key ? 'active' : ''}>{s.title}</a>)}
        </div>)}
      </nav>
      <div>
        {sections.length === 0 && <Card><EmptyState title="No matching section" description="Try another word, or clear the search."/></Card>}
        {sections.map(s => <section key={s.key} id={s.key} className="docs-section">
          <Card title={s.title} subtitle={s.summary} actions={s.path && <Link className="btn btn-secondary btn-link" to={s.path(base)}>Open <ArrowUpRight size={15}/></Link>}>
            <div className="docs-meta"><Badge tone="info">{s.group}</Badge><Badge tone="neutral">{s.who}</Badge></div>
            {s.steps?.length > 0 && <><h3>How it works</h3><ol>{s.steps.map((step, i) => <li key={i}>{step}</li>)}</ol></>}
            {s.rules?.length > 0 && <><h3>Rules to know</h3><ul>{s.rules.map((rule, i) => <li key={i}>{rule}</li>)}</ul></>}
          </Card>
        </section>)}
      </div>
    </div>
  </>;
}
