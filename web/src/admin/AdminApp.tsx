import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo } from 'react';
import { Route, Routes } from 'react-router';
import { Button } from '../design/components/Button';
import { Loading, Notice } from '../design/components/Notice';
import { useSiteTheme } from '../app/useSiteTheme';
import { AccountPage } from './AccountPage';
import { AdminLayout } from './AdminLayout';
import { BrandingPage } from './BrandingPage';
import { PaymentsPage } from './PaymentsPage';
import { SetupPage } from './SetupPage';
import { SystemPage } from './SystemPage';
import { UsersPage } from './UsersPage';
import { ForgotPage, LoginPage, ResetPage } from './AuthPages';
import { BlockedPage } from './BlockedPage';
import { BookingDetailPage } from './BookingDetailPage';
import { BookingsPage } from './BookingsPage';
import { AdminContext } from './context';
import { DashboardPage } from './DashboardPage';
import { isUnauthenticated, signedOut, useMe } from './hooks';
import { ProviderPage } from './ProviderPage';
import { ProvidersPage } from './ProvidersPage';
import '../app/chrome.css';
import './admin.css';

/** When any admin request finds the session gone, show sign-in. */
function useSessionExpiry() {
  const client = useQueryClient();
  useEffect(() => {
    const recheck = (error: unknown, key: readonly unknown[] | undefined) => {
      if (isUnauthenticated(error) && key?.[0] === 'admin' && key[1] !== 'me')
        void signedOut(client);
    };
    const offQueries = client.getQueryCache().subscribe((event) => {
      if (event.type === 'updated' && event.action.type === 'error') {
        recheck(event.action.error, event.query.queryKey);
      }
    });
    const offMutations = client.getMutationCache().subscribe((event) => {
      if (event.type === 'updated' && event.action.type === 'error') {
        recheck(event.action.error, ['admin']);
      }
    });
    return () => {
      offQueries();
      offMutations();
    };
  }, [client]);
}

export default function AdminApp({ segment }: { segment: string }) {
  const base = `/${segment}`;
  useSiteTheme('Admin');
  useSessionExpiry();
  const me = useMe();
  const user = me.data?.user;
  const context = useMemo(() => (user ? { segment, base, user } : null), [segment, base, user]);

  if (me.isPending) {
    return (
      <div className="admin-splash">
        <Loading />
      </div>
    );
  }

  if (context === null) {
    if (me.isError && !isUnauthenticated(me.error)) {
      return (
        <div className="admin-splash">
          <Notice tone="danger" title="The admin area isn't responding" live>
            <p>{me.error.message}</p>
            <Button variant="secondary" onClick={() => void me.refetch()}>
              Try again
            </Button>
          </Notice>
        </div>
      );
    }
    return (
      <Routes>
        <Route path="forgot" element={<ForgotPage segment={segment} />} />
        <Route path="reset" element={<ResetPage segment={segment} />} />
        <Route path="welcome" element={<ResetPage segment={segment} welcome />} />
        <Route path="*" element={<LoginPage segment={segment} />} />
      </Routes>
    );
  }

  return (
    <AdminContext.Provider value={context}>
      <AdminLayout>
        <Routes>
          <Route index element={<DashboardPage />} />
          <Route path="bookings" element={<BookingsPage />} />
          <Route path="bookings/:id" element={<BookingDetailPage />} />
          <Route path="providers" element={<ProvidersPage />} />
          <Route path="providers/:id" element={<ProviderPage />} />
          <Route path="blocked" element={<BlockedPage />} />
          <Route path="account" element={<AccountPage />} />
          {context.user.role === 'owner' && (
            <>
              <Route path="users" element={<UsersPage />} />
              <Route path="branding" element={<BrandingPage />} />
              <Route path="payments" element={<PaymentsPage />} />
              <Route path="system" element={<SystemPage />} />
              <Route path="setup" element={<SetupPage />} />
            </>
          )}
          <Route path="*" element={<DashboardPage />} />
        </Routes>
      </AdminLayout>
    </AdminContext.Provider>
  );
}
