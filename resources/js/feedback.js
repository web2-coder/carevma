let confirmSequence = 0;

export function requestConfirmation(options = {}) {
  if (typeof window === 'undefined') return Promise.resolve(true);
  const id = `carevma-confirm-${++confirmSequence}`;
  return new Promise(resolve => {
    const handler = event => {
      if (event.detail?.id !== id) return;
      window.removeEventListener('carevma:confirm-result', handler);
      resolve(Boolean(event.detail?.confirmed));
    };
    window.addEventListener('carevma:confirm-result', handler);
    window.dispatchEvent(new CustomEvent('carevma:confirm-request', {
      detail: {
        id,
        title: options.title || 'Confirm action',
        message: options.message || 'Are you sure you want to continue?',
        confirmLabel: options.confirmLabel || 'Confirm',
        cancelLabel: options.cancelLabel || 'Cancel',
        danger: Boolean(options.danger),
      },
    }));
  });
}

export function emitToast(message, type = 'success') {
  if (!message || typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent('carevma:toast', { detail: { message, type } }));
}

function humanResource(url = '') {
  const path = String(url).split('?')[0].replace(/^\/api\//, '/').replace(/^\//, '');
  if (path.includes('company-finance')) return 'finance entry';
  if (path.includes('employees')) return 'employee';
  if (path.includes('clients')) return 'client';
  if (path.includes('schedules')) return 'schedule';
  if (path.includes('invoices')) return 'invoice';
  if (path.includes('hr-users')) return 'HR account';
  if (path.includes('references')) return 'configuration item';
  if (path.includes('password')) return 'password';
  if (path.includes('account')) return 'account';
  if (path.includes('profile')) return 'profile';
  if (path.includes('overtime')) return 'overtime decision';
  if (path.includes('change-requests')) return 'attendance change request';
  return 'record';
}

export function confirmationForRequest(config = {}) {
  if (config.carevmaConfirm === false || config.carevmaConfirmed === true) return null;
  const method = String(config.method || 'get').toLowerCase();
  const url = String(config.url || '');
  const resource = humanResource(url);

  if (method === 'delete') {
    return {
      title: `Delete ${resource}?`,
      message: `Are you sure you want to delete this ${resource}? This action cannot be undone.`,
      confirmLabel: 'Delete',
      danger: true,
    };
  }

  if (['put', 'patch'].includes(method)) {
    return {
      title: `Update ${resource}?`,
      message: `Are you sure you want to save these changes to this ${resource}?`,
      confirmLabel: 'Save changes',
      danger: false,
    };
  }

  if (method === 'post' && (
    /\/profile(?:$|\?)/.test(url) ||
    /\/review(?:$|\?)/.test(url) ||
    /\/read-all(?:$|\?)/.test(url)
  )) {
    return {
      title: `Confirm ${resource} update`,
      message: `Are you sure you want to apply this update to the ${resource}?`,
      confirmLabel: 'Confirm',
      danger: false,
    };
  }

  return null;
}
