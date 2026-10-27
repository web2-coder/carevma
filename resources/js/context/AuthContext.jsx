import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import api from '../api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const refresh = async () => {
    try {
      const { data } = await api.get('/me');
      setUser(data.user);
      return data.user;
    } catch {
      setUser(null);
      return null;
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    refresh();
    const onUnauthorized = () => setUser(null);
    window.addEventListener('carevma:unauthorized', onUnauthorized);
    return () => window.removeEventListener('carevma:unauthorized', onUnauthorized);
  }, []);

  const login = async values => {
    const { data } = await api.post('/login', values);
    setUser(data.user);
    return data;
  };

  const logout = async () => {
    try { await api.post('/logout'); } finally { setUser(null); }
  };

  const value = useMemo(() => ({ user, loading, login, logout, refresh, setUser }), [user, loading]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
