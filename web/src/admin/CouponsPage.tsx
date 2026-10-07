import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { CouponForm } from './CouponForm';
import { useCoupons, useDeleteCoupon, useToggleCoupon } from './couponHooks';
import { formatDate } from './format';
import { Modal } from './Modal';
import type { AdminCoupon } from './types';

const rupees = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 0,
});

function discount(c: AdminCoupon): string {
  return c.kind === 'percent' ? `${c.value}% off` : `${rupees.format(c.value / 100)} off`;
}

function validity(c: AdminCoupon, timeZone: string): string {
  if (!c.valid_from && !c.valid_until) return 'Always';
  if (!c.valid_from) return `Until ${formatDate(c.valid_until as string, timeZone)}`;
  if (!c.valid_until) return `From ${formatDate(c.valid_from, timeZone)}`;
  return `${formatDate(c.valid_from, timeZone)} – ${formatDate(c.valid_until, timeZone)}`;
}

function scope(c: AdminCoupon): string {
  const who = c.provider_name ?? 'Every teacher';
  const sessions = c.service_ids?.length ?? 0;
  return sessions === 0 ? who : `${who} · ${sessions} ${sessions === 1 ? 'session' : 'sessions'}`;
}

/** Discount codes: owners and admins see all, teachers manage their own. */
export function CouponsPage() {
  const coupons = useCoupons();
  const toggle = useToggleCoupon();
  const remove = useDeleteCoupon();
  const [editing, setEditing] = useState<AdminCoupon | 'new' | null>(null);
  const [deleting, setDeleting] = useState<number | null>(null);
  const tz = visitorTimezone();
  const problem = [toggle, remove].find((m) => m.isError)?.error;

  return (
    <div className="page">
      <header className="page__head page__head--row">
        <div>
          <h1>Coupons</h1>
          <p className="page__sub">
            Codes customers can enter when booking. Bookings that lapse or are cancelled give their
            use back.
          </p>
        </div>
        <Button onClick={() => setEditing('new')}>New coupon</Button>
      </header>
      {coupons.isPending && <Loading />}
      {coupons.isError && (
        <Notice tone="danger" live>
          {coupons.error.message}
        </Notice>
      )}
      {problem && (
        <Notice tone="danger" live>
          {problem.message}
        </Notice>
      )}
      {coupons.data?.length === 0 && (
        <p className="empty">
          No coupons yet. Make one for a launch offer, students or a workshop.
        </p>
      )}
      {coupons.data && coupons.data.length > 0 && (
        <div className="table-wrap">
          <table className="table" aria-label="Coupons">
            <thead>
              <tr>
                <th scope="col">Code</th>
                <th scope="col">Discount</th>
                <th scope="col">For</th>
                <th scope="col">Valid</th>
                <th scope="col">Used</th>
                <th scope="col">
                  <span className="visually-hidden">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {coupons.data.map((c) => (
                <tr key={c.id} className={c.active ? undefined : 'row--muted'}>
                  <td>
                    <span className="cell-main mono">{c.code}</span>
                    {c.note && <span className="cell-sub">{c.note}</span>}
                  </td>
                  <td>{discount(c)}</td>
                  <td>{scope(c)}</td>
                  <td>{validity(c, tz)}</td>
                  <td>
                    {c.uses === null
                      ? '—'
                      : c.max_uses === null
                        ? c.uses
                        : `${c.uses} of ${c.max_uses}`}
                    {c.once_per_email && <span className="cell-sub">once per customer</span>}
                  </td>
                  <td className="cell-actions">
                    {!c.editable ? (
                      <Badge>Site-wide</Badge>
                    ) : deleting === c.id ? (
                      <>
                        <Button
                          className="btn--danger"
                          onClick={() =>
                            remove.mutate(c.id, { onSettled: () => setDeleting(null) })
                          }
                        >
                          Delete {c.code}
                        </Button>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                          Keep
                        </Button>
                      </>
                    ) : (
                      <>
                        {!c.active && <Badge tone="warn">Off</Badge>}
                        <Button
                          variant="ghost"
                          onClick={() => setEditing(c)}
                          aria-label={`Edit ${c.code}`}
                        >
                          Edit
                        </Button>
                        <Button
                          variant="ghost"
                          onClick={() => toggle.mutate({ id: c.id, active: !c.active })}
                        >
                          {c.active ? 'Turn off' : 'Turn on'}
                        </Button>
                        <Button variant="ghost" onClick={() => setDeleting(c.id)}>
                          Delete
                        </Button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing !== null && (
        <Modal
          title={editing === 'new' ? 'New coupon' : `Edit ${editing.code}`}
          onClose={() => setEditing(null)}
        >
          <CouponForm coupon={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />
        </Modal>
      )}
    </div>
  );
}
