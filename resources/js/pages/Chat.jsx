import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle, MessageCircle, Search, Send } from 'lucide-react';
import api from '../api';
import { useAuth } from '../context/AuthContext';

const roleLabel = role => {
  if (role === 'client' || role === 'doctor') return 'Doctor / Client';
  if (role === 'employee') return 'VA / Employee';
  if (role === 'hr') return 'HR';
  if (role === 'superadmin') return 'Super Admin';
  if (role === 'admin') return 'Administrator';
  return role?.toUpperCase?.() || 'CareVMA User';
};

const roleGroup = role => {
  if (role === 'admin' || role === 'superadmin') return 'Administrators';
  if (role === 'hr') return 'HR';
  if (role === 'client' || role === 'doctor') return 'Doctors / Clients';
  if (role === 'employee') return 'VAs / Employees';
  return 'Other Users';
};

const GROUP_ORDER = ['Administrators', 'HR', 'Doctors / Clients', 'VAs / Employees', 'Other Users'];

export default function ChatPage() {
  const { user } = useAuth();
  const [convos, setConvos] = useState([]);
  const [contacts, setContacts] = useState([]);
  const [active, setActive] = useState(null);
  const [activeUser, setActiveUser] = useState(null);
  const [messages, setMessages] = useState([]);
  const [body, setBody] = useState('');
  const [q, setQ] = useState('');
  const [chatError, setChatError] = useState('');

  const loadContacts = async () => {
    try {
      const { data } = await api.get('/chat/users', { carevmaAutoToast: false });
      setContacts(data.data || []);
    } catch (error) {
      setChatError(error?.response?.data?.message || 'Could not load CareVMA chat contacts.');
    }
  };

  const loadConvos = async () => {
    try {
      const { data } = await api.get('/chat/conversations', { carevmaAutoToast: false });
      setConvos(data.data || []);
      if (data.setup_required) setChatError(data.message || 'Chat database setup is incomplete.');
      else setChatError(current => current?.includes('database setup') ? '' : current);
    } catch (error) {
      setChatError(error?.response?.data?.message || 'Could not load conversations.');
    }
  };

  const loadMessages = async id => {
    if (!id) return;
    try {
      const { data } = await api.get(`/chat/conversations/${id}/messages`, { carevmaAutoToast: false });
      setMessages(data.data || []);
      if (data.setup_required) setChatError(data.message || 'Chat database setup is incomplete.');
    } catch (error) {
      setChatError(error?.response?.data?.message || 'Could not load this conversation.');
    }
  };

  useEffect(() => {
    loadContacts();
    loadConvos();
  }, []);

  useEffect(() => {
    const timer = setInterval(() => {
      loadConvos();
      if (active) loadMessages(active);
    }, 5000);
    return () => clearInterval(timer);
  }, [active]);

  const convoByUser = useMemo(() => {
    const map = new Map();
    convos.forEach(convo => {
      if (convo.user?.id) map.set(Number(convo.user.id), convo);
    });
    return map;
  }, [convos]);

  const current = useMemo(() => convos.find(convo => Number(convo.id) === Number(active)), [convos, active]);
  const currentUser = current?.user || activeUser;

  const groupedContacts = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const filtered = contacts.filter(contact => {
      if (!needle) return true;
      return [contact.name, roleLabel(contact.role), contact.role]
        .filter(Boolean)
        .some(value => String(value).toLowerCase().includes(needle));
    });

    return GROUP_ORDER.map(group => ({
      group,
      users: filtered.filter(contact => roleGroup(contact.role) === group),
    })).filter(section => section.users.length > 0);
  }, [contacts, q]);

  const openContact = async contact => {
    setActiveUser(contact);
    setChatError('');
    const existing = convoByUser.get(Number(contact.id));
    if (existing) {
      setActive(existing.id);
      await loadMessages(existing.id);
      return;
    }

    try {
      const { data } = await api.post('/chat/conversations', { user_id: contact.id }, { carevmaAutoToast: false });
      setActive(data.id);
      await loadConvos();
      await loadMessages(data.id);
    } catch (error) {
      setChatError(error?.response?.data?.message || 'Could not start this conversation.');
    }
  };

  const send = async event => {
    event.preventDefault();
    if (!body.trim() || !active) return;

    try {
      await api.post(`/chat/conversations/${active}/messages`, { body }, { carevmaAutoToast: false });
      setBody('');
      await loadMessages(active);
      await loadConvos();
    } catch (error) {
      setChatError(error?.response?.data?.message || 'Could not send this message.');
    }
  };

  return (
    <div className="cv-chat-page">
      <div className="cv-chat-head">
        <div>
          <h1>Chat</h1>
          <p>Secure internal CareVMA messaging.</p>
        </div>
      </div>

      <div className="cv-chat-shell">
        <aside className="cv-chat-list">
          <div className="cv-chat-search">
            <Search size={17} />
            <input value={q} onChange={event => setQ(event.target.value)} placeholder="Search all CareVMA users..." />
          </div>

          {chatError && (
            <div className="cv-chat-inline-error">
              <AlertTriangle size={16} />
              <span>{chatError}</span>
            </div>
          )}

          <div className="cv-chat-people">
            {groupedContacts.map(section => (
              <div className="cv-chat-group" key={section.group}>
                <div className="cv-chat-group-title">{section.group}</div>
                {section.users.map(contact => {
                  const conversation = convoByUser.get(Number(contact.id));
                  const selected = Number(activeUser?.id || current?.user?.id) === Number(contact.id);
                  return (
                    <button
                      type="button"
                      className={`cv-chat-contact ${selected ? 'active' : ''}`}
                      key={contact.id}
                      onClick={() => openContact(contact)}
                    >
                      {contact.profile_picture ? (
                        <img className="cv-chat-avatar cv-chat-avatar-img" src={contact.profile_picture} alt="" />
                      ) : (
                        <div className="cv-chat-avatar">{(contact.name || '?').slice(0, 1).toUpperCase()}</div>
                      )}
                      <div className="cv-chat-contact-copy">
                        <b>{contact.name}</b>
                        <span>{conversation?.last_message || roleLabel(contact.role)}</span>
                      </div>
                      {conversation?.unread > 0 && <i>{conversation.unread}</i>}
                    </button>
                  );
                })}
              </div>
            ))}

            {!groupedContacts.length && <div className="cv-chat-no-people">No matching CareVMA users.</div>}
          </div>
        </aside>

        <section className="cv-chat-thread">
          {!active ? (
            <div className="cv-chat-empty">
              <MessageCircle size={44} />
              <h3>Select a person</h3>
              <p>{['admin', 'superadmin'].includes(user?.role) ? 'Choose any CareVMA user from the sidebar to start or continue a chat.' : 'Choose an available person to start or continue a chat.'}</p>
            </div>
          ) : (
            <>
              <header>
                <b>{currentUser?.name || 'Conversation'}</b>
                <span>{roleLabel(currentUser?.role)}</span>
              </header>
              <div className="cv-chat-messages">
                {messages.map(message => (
                  <div key={message.id} className={`cv-msg ${Number(message.sender_id) === Number(user.id) ? 'mine' : ''}`}>
                    <div>{message.body}</div>
                    <small>{message.sender?.name} · {new Date(message.created_at).toLocaleString()}</small>
                  </div>
                ))}
              </div>
              <form onSubmit={send}>
                <textarea value={body} onChange={event => setBody(event.target.value)} placeholder="Type a message..." />
                <button type="submit"><Send size={17} /> Send</button>
              </form>
            </>
          )}
        </section>
      </div>
    </div>
  );
}
