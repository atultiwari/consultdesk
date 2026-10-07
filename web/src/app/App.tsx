import { QueryClientProvider } from '@tanstack/react-query';
import { lazy, Suspense, type ReactNode } from 'react';
import { BrowserRouter, Outlet, Route, Routes } from 'react-router';
import { Loading } from '../design/components/Notice';
import { createQueryClient } from './queryClient';
import { SiteChrome } from './SiteChrome';

const HomePage = lazy(() => import('../booking/HomePage'));
const ProviderPage = lazy(() => import('../booking/ProviderPage'));
const BookingPage = lazy(() => import('../booking/BookingPage'));
const StatusPage = lazy(() => import('../booking/StatusPage'));
const MyBookingsPage = lazy(() => import('../booking/MyBookingsPage'));
const NotFoundPage = lazy(() => import('../booking/NotFoundPage'));
const AdminGate = lazy(() => import('../admin/AdminGate'));

function PublicLayout({ children = <Outlet /> }: { children?: ReactNode }) {
  return (
    <SiteChrome>
      <Suspense
        fallback={
          <div className="container">
            <Loading />
          </div>
        }
      >
        {children}
      </Suspense>
    </SiteChrome>
  );
}

export function AppRoutes() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/p/:provider" element={<ProviderPage />} />
        <Route path="/p/:provider/:service" element={<BookingPage />} />
        <Route path="/b/:ref" element={<StatusPage />} />
        <Route path="/my-bookings" element={<MyBookingsPage />} />
      </Route>
      {/* Any other first segment might be the secret admin path; the gate asks the API. */}
      <Route
        path="/:segment/*"
        element={
          <Suspense fallback={null}>
            <AdminGate
              notFound={
                <PublicLayout>
                  <NotFoundPage />
                </PublicLayout>
              }
            />
          </Suspense>
        }
      />
    </Routes>
  );
}

export default function App() {
  return (
    <QueryClientProvider client={createQueryClient()}>
      <BrowserRouter>
        <AppRoutes />
      </BrowserRouter>
    </QueryClientProvider>
  );
}
