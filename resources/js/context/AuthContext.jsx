import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import api, { primeCsrf } from '../api';
import { cachePersonalMode, cachedPersonalMode, setPersonalThemeMode } from '../branding';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const refresh = async () => {
    try {
      const { data } = await api.get('/me', { carevmaSilent: true });
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
    const onTimezoneUpdated = () => refresh();
    window.addEventListener('carevma:unauthorized', onUnauthorized);
    window.addEventListener('carevma:timezone-updated', onTimezoneUpdated);
    return () => {
      window.removeEventListener('carevma:unauthorized', onUnauthorized);
      window.removeEventListener('carevma:timezone-updated', onTimezoneUpdated);
    };
  }, []);

  useEffect(() => {
    let active = true;
    // Switching accounts immediately clears the previous user’s override.
    setPersonalThemeMode(null);
    if (!user?.id) return () => { active = false; };
    const cached = cachedPersonalMode(user.id);
    if (cached) setPersonalThemeMode(cached);
    api.get('/profile/theme-mode', { carevmaSilent: true, carevmaPageLoader: false })
      .then(({data}) => {
        if (!active) return;
        const mode = data?.mode === 'dark' ? 'dark' : 'light';
        cachePersonalMode(user.id, mode);
        setPersonalThemeMode(mode);
      })
      .catch(() => {
        // Fail closed: no other user's saved appearance can be used.
        if (active && !cached) setPersonalThemeMode('light');
      });
    return () => { active = false; };
  }, [user?.id]);

  const login = async values => {
    // Login must start from a CSRF token tied to the current session cookie.
    // Doing this explicitly prevents the recurring 419 / refresh-required loop.
    await primeCsrf();
    const { data } = await api.post('/login', values);
    setUser(data.user);
    return data;
  };

  const logout = async () => {
    try {
      await api.post('/logout');
    } finally {
      setUser(null);
      // Laravel invalidates the session and rotates its CSRF token on logout.
      // Prepare the guest session immediately so the next login works without
      // a hard browser refresh.
      primeCsrf().catch(() => {});
    }
  };

  const value = useMemo(() => ({ user, loading, login, logout, refresh, setUser }), [user, loading]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
