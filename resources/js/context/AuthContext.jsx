import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';

import axios from 'axios';
import api from '../api';

const AuthContext = createContext(null);

/*
|--------------------------------------------------------------------------
| Fresh Laravel CSRF Cookie
|--------------------------------------------------------------------------
|
| This request intentionally does NOT use our /api baseURL.
|
| Correct:
|   /sanctum/csrf-cookie
|
| Not:
|   /api/sanctum/csrf-cookie
|
*/

const refreshCsrfCookie = async () => {
  await axios.get(
    '/sanctum/csrf-cookie',
    {
      withCredentials: true,

      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    },
  );
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  /*
  |--------------------------------------------------------------------------
  | Refresh Current User
  |--------------------------------------------------------------------------
  */

  const refresh = async () => {
    try {
      const { data } = await api.get(
        '/me',
        {
          carevmaSilent: true,
        },
      );

      setUser(data.user);

      return data.user;
    } catch {
      setUser(null);

      return null;
    } finally {
      setLoading(false);
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Initial Authentication Check
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    refresh();

    const onUnauthorized = () => {
      setUser(null);
    };

    window.addEventListener(
      'carevma:unauthorized',
      onUnauthorized,
    );

    return () => {
      window.removeEventListener(
        'carevma:unauthorized',
        onUnauthorized,
      );
    };
  }, []);

  /*
  |--------------------------------------------------------------------------
  | Login
  |--------------------------------------------------------------------------
  |
  | 1. Request a fresh Laravel CSRF cookie.
  | 2. Axios reads XSRF-TOKEN.
  | 3. Axios sends it as X-XSRF-TOKEN to /api/login.
  | 4. Laravel validates CSRF + session.
  |
  */

  const login = async values => {
    await refreshCsrfCookie();

    const { data } = await api.post(
      '/login',
      values,
    );

    setUser(data.user);

    return data;
  };

  /*
  |--------------------------------------------------------------------------
  | Logout
  |--------------------------------------------------------------------------
  */

  const logout = async () => {
    try {
      /*
       * Refresh CSRF before logout as logout is also a POST request.
       */
      await refreshCsrfCookie();

      await api.post('/logout');
    } finally {
      setUser(null);
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Context
  |--------------------------------------------------------------------------
  */

  const value = useMemo(
    () => ({
      user,
      loading,
      login,
      logout,
      refresh,
      setUser,
    }),
    [
      user,
      loading,
    ],
  );

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () =>
  useContext(AuthContext);