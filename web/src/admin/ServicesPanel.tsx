import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { formatMoney } from './format';
import { useReorderServices, useServices } from './hooks';
import { ServiceEditor } from './ServiceEditor';
import type { AdminService } from './types';

export function ServicesPanel({ providerId }: { providerId: number }) {
  const services = useServices(providerId);
  const reorder = useReorderServices(providerId);
  const move = (index: number, by: -1 | 1) => {
    const ids = (services.data ?? []).map((s) => s.id);
    const [moved] = ids.splice(index, 1);
    ids.splice(index + by, 0, moved);
    reorder.mutate(ids);
  };
  const [editing, setEditing] = useState<AdminService | 'new' | null>(null);

  return (
    <div className="stack">
      <div className="panel-head">
        <p>
          Each session has its own length, price and questions. The booking site lists them in this
          order.
        </p>
        <Button onClick={() => setEditing('new')}>Add session</Button>
      </div>
      {services.isPending && <Loading />}
      {services.isError && (
        <Notice tone="danger" live>
          {services.error.message}
        </Notice>
      )}
      {services.data?.length === 0 && <p className="empty">No sessions yet.</p>}
      {reorder.isError && (
        <Notice tone="danger" live>
          {reorder.error.message}
        </Notice>
      )}
      <ul className="rows">
        {services.data?.map((s, i, all) => (
          <li key={s.id} className="rows__item">
            <span className="order-buttons">
              <button
                type="button"
                className="order-button"
                aria-label={`Move ${s.title} up`}
                disabled={i === 0 || reorder.isPending}
                onClick={() => move(i, -1)}
              >
                ↑
              </button>
              <button
                type="button"
                className="order-button"
                aria-label={`Move ${s.title} down`}
                disabled={i === all.length - 1 || reorder.isPending}
                onClick={() => move(i, 1)}
              >
                ↓
              </button>
            </span>
            <span className="rows__main">
              <span className="cell-main">{s.title}</span>
              <span className="cell-sub">
                {s.duration_min} min ·{' '}
                {s.price_minor > 0 ? formatMoney(s.price_minor, s.currency) : 'Free'} ·{' '}
                {s.questions.length} {s.questions.length === 1 ? 'question' : 'questions'}
                {s.price_minor > 0 &&
                  ` · ${s.payment_methods.map((m) => (m === 'upi' ? 'UPI' : m === 'razorpay_link' ? 'Online' : m)).join(' + ')}`}
              </span>
            </span>
            {s.highlight && <Badge tone="brand">{s.highlight}</Badge>}
            {s.requires_approval && <Badge tone="accent">Needs approval</Badge>}
            {!s.active && <Badge>Hidden</Badge>}
            <Button
              variant="secondary"
              aria-label={`Edit ${s.title}`}
              onClick={() => setEditing(s)}
            >
              Edit
            </Button>
          </li>
        ))}
      </ul>
      {editing && (
        <ServiceEditor
          providerId={providerId}
          service={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  );
}
