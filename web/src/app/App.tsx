import { QueryClientProvider } from '@tanstack/react-query';
import { lazy, Suspense } from 'react';
import { BrowserRouter, Route, Routes } from 'react-router';
import { Loading } from '../design/components/Notice';
import { createQueryClient } from './queryClient';
import { SiteChrome } from './SiteChrome';

const HomePage = lazy(() => import('../booking/HomePage'));
const ProviderPage = lazy(() => import('../booking/ProviderPage'));
const BookingPage = lazy(() => import('../booking/BookingPage'));
const StatusPage = lazy(() => import('../booking/StatusPage'));
const NotFoundPage = lazy(() => import('../booking/NotFoundPage'));

export function AppRoutes() {
  return (
    <SiteChrome>
      <Suspense
        fallback={
          <div className="container">
            <Loading />
          </div>
        }
      >
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/p/:provider" element={<ProviderPage />} />
          <Route path="/p/:provider/:service" element={<BookingPage />} />
          <Route path="/b/:ref" element={<StatusPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Suspense>
    </SiteChrome>
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
