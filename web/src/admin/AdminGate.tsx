import { lazy, Suspense, type ReactNode } from 'react';
import { useParams } from 'react-router';
import { Loading } from '../design/components/Notice';
import { useAdminEntry } from './hooks';

const AdminApp = lazy(() => import('./AdminApp'));

/** Same rule as ADMIN_PATH on the server; anything else cannot be the admin area. */
const ADMIN_SEGMENT = /^[a-z0-9][a-z0-9-]{7,63}$/;

/**
 * Decides whether the first path segment is the secret admin path. The path itself never ships
 * in the JavaScript: the API answers 200 for the right one and 404 for anything else.
 */
export default function AdminGate({ notFound }: { notFound: ReactNode }) {
  const { segment = '' } = useParams();
  const plausible = ADMIN_SEGMENT.test(segment);
  const entry = useAdminEntry(segment, plausible);

  if (!plausible || entry.isError) return notFound;
  if (entry.isPending) {
    return (
      <div className="admin-splash">
        <Loading />
      </div>
    );
  }

  return (
    <Suspense
      fallback={
        <div className="admin-splash">
          <Loading />
        </div>
      }
    >
      <AdminApp segment={segment} firstRun={entry.data.first_run === true ? entry.data : null} />
    </Suspense>
  );
}
