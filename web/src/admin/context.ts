import { createContext, useContext } from 'react';
import type { AdminUser } from './types';

export type AdminContextValue = {
  /** The secret admin path segment, without slashes. */
  segment: string;
  /** Absolute path of the admin area, e.g. "/desk-7q2x". */
  base: string;
  user: AdminUser;
};

export const AdminContext = createContext<AdminContextValue | null>(null);

export function useAdmin(): AdminContextValue {
  const value = useContext(AdminContext);
  if (value === null) throw new Error('useAdmin outside the admin area');
  return value;
}

export function isStaff(user: AdminUser): boolean {
  return user.role !== 'provider';
}
