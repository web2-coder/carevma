import { useAuth } from './context/AuthContext';

export const ADMIN_ROLES = ['admin', 'superadmin'];

export function can(user, module, action = 'view') {
  if (!user) return false;
  if (ADMIN_ROLES.includes(user.role)) return true;
  return action === 'view'
    ? (user.permissions || []).includes(module)
    : (user.action_permissions || []).includes(`${module}.${action}`);
}

export function useCan(module) {
  const { user } = useAuth();
  return action => can(user, module, action);
}

export function referenceModule(type) {
  if (type === 'services') return 'client-services';
  if (type === 'contracts') return 'contract-types';
  return 'configuration';
}
