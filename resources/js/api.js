import axios from 'axios';
import {
  confirmationForRequest,
  emitToast,
  requestConfirmation,
} from './feedback';

/*
|--------------------------------------------------------------------------
| CareVMA Axios API Client
|--------------------------------------------------------------------------
|
| API requests go to /api.
| Laravel's XSRF-TOKEN cookie is automatically sent back through the
| X-XSRF-TOKEN header for protected requests.
|
*/

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

// Never pin a CSRF token here. Laravel rotates the session token during login
// (session()->regenerate() calls regenerateToken()), and VerifyCsrfToken refreshes the
// XSRF-TOKEN cookie on every response. Axios reads that cookie per request and sends
// X-XSRF-TOKEN, which Laravel consults only when X-CSRF-TOKEN is absent -- so a pinned
// X-CSRF-TOKEN header wins, goes stale at login, and 419s every later write.

/*
|--------------------------------------------------------------------------
| Page Request Tracking
|--------------------------------------------------------------------------
*/

let pagePendingRequests = 0;

const emitPageRequestState = () => {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(
      new CustomEvent('carevma:page-request-state', {
        detail: {
          pending: pagePendingRequests,
        },
      }),
    );
  }
};

/*
|--------------------------------------------------------------------------
| Request Interceptor
|--------------------------------------------------------------------------
*/

api.interceptors.request.use(
  async config => {
    config.headers = config.headers || {};

    /*
     * Add a unique request ID.
     */
    config.headers['X-Request-ID'] =
      config.headers['X-Request-ID'] ||
      (
        globalThis.crypto?.randomUUID?.() ||
        `cvma-${Date.now()}-${Math.random().toString(36).slice(2)}`
      );

    /*
     * Existing CareVMA confirmation handling.
     */
    const confirmation = confirmationForRequest(config);

    if (confirmation) {
      const confirmed = await requestConfirmation(confirmation);

      if (!confirmed) {
        const error = new axios.CanceledError(
          'Action cancelled by user.',
        );

        error.carevmaCancelled = true;

        throw error;
      }

      config.carevmaConfirmed = true;
    }

    /*
     * Existing page-navigation request tracking.
     */
    const method = String(
      config?.method || 'get',
    ).toLowerCase();

    const navigationActive =
      typeof window !== 'undefined' &&
      window.__carevmaPageNavigationActive === true;

    const silent =
      config?.carevmaSilent === true;

    if (
      method === 'get' &&
      navigationActive &&
      !silent
    ) {
      config.__carevmaPageTracked = true;

      pagePendingRequests += 1;

      emitPageRequestState();
    }

    return config;
  },

  error => Promise.reject(error),
);

/*
|--------------------------------------------------------------------------
| Settle Tracked Request
|--------------------------------------------------------------------------
*/

const settlePageRequest = config => {
  if (!config?.__carevmaPageTracked) {
    return;
  }

  pagePendingRequests = Math.max(
    0,
    pagePendingRequests - 1,
  );

  emitPageRequestState();
};

/*
|--------------------------------------------------------------------------
| Response Interceptor
|--------------------------------------------------------------------------
*/

api.interceptors.response.use(
  response => {
    settlePageRequest(response?.config);

    const method = String(
      response?.config?.method || 'get',
    ).toLowerCase();

    const shouldShowSuccessToast =
      response?.config?.carevmaAutoToast !== false &&
      (
        ['put', 'patch', 'delete'].includes(method) ||
        response?.config?.carevmaConfirmed
      );

    if (shouldShowSuccessToast) {
      emitToast(
        response?.data?.message ||
          (
            method === 'delete'
              ? 'Record deleted successfully.'
              : 'Changes saved successfully.'
          ),
        'success',
      );
    }

    return response;
  },

  error => {
    settlePageRequest(error?.config);

    if (
      error?.carevmaCancelled ||
      axios.isCancel(error)
    ) {
      return Promise.reject(error);
    }

    /*
     * Session authentication expired.
     */
    if (
      error?.response?.status === 401 &&
      typeof window !== 'undefined'
    ) {
      window.dispatchEvent(
        new CustomEvent('carevma:unauthorized'),
      );
    }

    /*
     * Employee profile incomplete.
     */
    if (
      error?.response?.status === 423 &&
      error?.response?.data?.code === 'PROFILE_INCOMPLETE'
    ) {
      if (typeof window !== 'undefined') {
        window.dispatchEvent(
          new CustomEvent(
            'carevma:profile-incomplete',
            {
              detail: error.response.data,
            },
          ),
        );

        if (
          window.location.pathname !==
          '/employee/profile'
        ) {
          window.history.replaceState(
            {},
            '',
            '/employee/profile',
          );

          window.dispatchEvent(
            new PopStateEvent('popstate'),
          );
        }
      }
    }

    /*
     * Show friendly API error.
     */
    // Callers that pass carevmaSilent own their own error handling. The auth probe
    // on first load (/me) is the case that matters: a visitor who has never signed
    // in gets a 401 there, and "your session has expired" is simply wrong for them.
    const message = error?.config?.carevmaSilent === true ? '' : messageFrom(error);

    if (message) {
      emitToast(
        message,
        'error',
      );
    }

    return Promise.reject(error);
  },
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

export const isCancelled = error =>
  Boolean(
    error?.carevmaCancelled ||
    axios.isCancel(error),
  );

/*
|--------------------------------------------------------------------------
| Friendly Error Messages
|--------------------------------------------------------------------------
*/

export const messageFrom = error => {
  if (isCancelled(error)) {
    return '';
  }

  if (!error?.response) {
    return 'CareVMA could not reach the server. Check your internet connection and try again.';
  }

  const status = Number(
    error.response.status || 0,
  );

  const serverMessage = String(
    error?.response?.data?.message || '',
  ).trim();

  const validationMessage = Object.values(
    error?.response?.data?.errors || {},
  )
    .flat()
    .find(Boolean);

  if (validationMessage) {
    return String(validationMessage);
  }

  if (serverMessage) {
    return serverMessage;
  }

  if (status === 401) {
    return 'Your session has expired. Please sign in again.';
  }

  if (status === 403) {
    return 'You do not have permission to perform this action.';
  }

  if (status === 404) {
    return 'The requested record could not be found. Refresh the page and try again.';
  }

  if (status === 409) {
    return 'This record changed while you were working. Refresh the page and try again.';
  }

  if (status === 419) {
    return 'Your session security token expired. Refresh the page and try again.';
  }

  if (status === 422) {
    return 'Please review the information you entered and try again.';
  }

  if (status === 429) {
    return 'Too many requests were sent. Please wait a moment and try again.';
  }

  if (status >= 500) {
    return 'CareVMA encountered a server problem. Please try again; if it continues, contact an administrator.';
  }

  return 'The request could not be completed. Please try again.';
};

export default api;