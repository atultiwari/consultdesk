import { Link } from 'react-router';
import { Loading, Notice } from '../design/components/Notice';
import { useAdmin } from './context';
import { useAdminProviders } from './hooks';

/** Where each provider's customers pay. UPI details are edited on the provider's profile. */
export function PaymentsPage() {
  const { base } = useAdmin();
  const providers = useAdminProviders();

  return (
    <div className="page">
      <header className="page__head">
        <h1>Payments</h1>
        <p className="page__sub">
          Customers pay each provider's UPI ID directly; the provider confirms the UTR. Card and
          netbanking payments through Razorpay come in a later update.
        </p>
      </header>
      {providers.isPending && <Loading />}
      {providers.isError && (
        <Notice tone="danger" live>
          {providers.error.message}
        </Notice>
      )}
      {providers.data && (
        <div className="table-wrap">
          <table className="table" aria-label="UPI details">
            <thead>
              <tr>
                <th scope="col">Provider</th>
                <th scope="col">UPI ID</th>
                <th scope="col">Payee name</th>
                <th scope="col">
                  <span className="visually-hidden">Edit</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {providers.data.map((p) => (
                <tr key={p.id}>
                  <td className="cell-main">{p.name}</td>
                  <td>
                    {p.upi_vpa ? (
                      <span className="mono">{p.upi_vpa}</span>
                    ) : (
                      <span className="cell-sub">Not set — UPI is off</span>
                    )}
                  </td>
                  <td>{p.upi_payee_name ?? '—'}</td>
                  <td>
                    <Link
                      to={`${base}/providers/${p.id}`}
                      aria-label={`Edit ${p.name}'s UPI details`}
                    >
                      Edit
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
