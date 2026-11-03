import { useAuth } from './context/AuthContext';

// Module access as granted on the admin Roles & Permissions page. Admins hold every module;
// other staff roles carry the keys the server put in `user.permissions`.
export const ADMIN_ROLES = ['admin', 'superadmin'];
export const can = (user, module) => !!user && (ADMIN_ROLES.includes(user.role) || (user.permissions || []).includes(module));
export function useCan() {
  const { user } = useAuth();
  return module => can(user, module);
}
