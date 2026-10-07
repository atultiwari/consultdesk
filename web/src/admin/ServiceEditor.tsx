import { useState, type FormEvent } from 'react';
import type { PaymentMethod } from '../api/types';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { fieldErrors, orNull, slugify } from './forms';
import { useSaveService } from './hooks';
import { Modal } from './Modal';
import { QuestionBuilder } from './QuestionBuilder';
import { toDrafts, toQuestions } from './questions';
import type { AdminService } from './types';

type Props = { providerId: number; service: AdminService | null; onClose: () => void };

export function ServiceEditor({ providerId, service, onClose }: Props) {
  const save = useSaveService(providerId);
  const [title, setTitle] = useState(service?.title ?? '');
  const [slug, setSlug] = useState(service?.slug ?? '');
  const [tagline, setTagline] = useState(service?.tagline ?? '');
  const [description, setDescription] = useState(service?.description ?? '');
  const [audience, setAudience] = useState(service?.audience ?? '');
  const [highlight, setHighlight] = useState(service?.highlight ?? '');
  const [duration, setDuration] = useState(String(service?.duration_min ?? 60));
  const [price, setPrice] = useState(service ? String(service.price_minor / 100) : '0');
  const [methods, setMethods] = useState<PaymentMethod[]>(
    service?.payment_methods.filter((m) => m !== 'free') ?? ['upi'],
  );
  const [approval, setApproval] = useState(service?.requires_approval ?? false);
  const [active, setActive] = useState(service?.active ?? true);
  const [questions, setQuestions] = useState(() => toDrafts(service?.questions ?? []));
  const errors = fieldErrors(save.error);
  const paid = Number(price) > 0;

  const toggleMethod = (method: PaymentMethod, on: boolean) =>
    setMethods((current) => (on ? [...current, method] : current.filter((m) => m !== method)));

  const [priceProblem, setPriceProblem] = useState<string | null>(null);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (price.trim() === '' || !Number.isFinite(Number(price))) {
      setPriceProblem('Enter a price, or 0 for a free session.');
      return;
    }
    setPriceProblem(null);
    const priceMinor = Math.round(Number(price) * 100);
    save.mutate(
      {
        id: service?.id ?? null,
        body: {
          title: title.trim(),
          slug: slug.trim(),
          tagline: orNull(tagline),
          description: orNull(description),
          audience: orNull(audience),
          highlight: highlight.trim(),
          duration_min: /^\d+$/.test(duration) ? Number(duration) : duration,
          price_minor: priceMinor,
          payment_methods: paid ? methods : ['free'],
          requires_approval: approval,
          active,
          questions: toQuestions(questions),
        },
      },
      { onSuccess: onClose },
    );
  };

  return (
    <Modal title={service ? 'Edit session' : 'New session'} onClose={onClose}>
      <form className="stack" onSubmit={submit} noValidate>
        {save.isError && (
          <Notice tone="danger" live>
            {Object.keys(errors).length > 0 ? 'Check the highlighted fields.' : save.error.message}
          </Notice>
        )}
        <Field label="Title" error={errors.title}>
          <input
            className="input"
            value={title}
            onChange={(e) => {
              setTitle(e.target.value);
              if (!service) setSlug(slugify(e.target.value));
            }}
          />
        </Field>
        <Field label="Web address" error={errors.slug}>
          <input
            className="input mono"
            value={slug}
            onChange={(e) => setSlug(e.target.value.toLowerCase())}
          />
        </Field>
        <Field label="One-line summary" error={errors.tagline}>
          <input className="input" value={tagline} onChange={(e) => setTagline(e.target.value)} />
        </Field>
        <Field label="Description" error={errors.description}>
          <textarea
            className="input"
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
        </Field>
        <Field label="Who it's for" hint="e.g. residents, PhD scholars" error={errors.audience}>
          <input className="input" value={audience} onChange={(e) => setAudience(e.target.value)} />
        </Field>
        <HighlightField value={highlight} onChange={setHighlight} error={errors.highlight} />
        <div className="form-row">
          <Field label="Length (minutes)" error={errors.duration_min}>
            <input
              className="input input--short"
              type="number"
              min={5}
              max={1440}
              step={5}
              value={duration}
              onChange={(e) => setDuration(e.target.value)}
            />
          </Field>
          <Field
            label="Price (₹)"
            hint="0 makes it free."
            error={priceProblem ?? errors.price_minor}
          >
            <input
              className="input input--short"
              type="number"
              min={0}
              step={1}
              value={price}
              onChange={(e) => setPrice(e.target.value)}
            />
          </Field>
        </div>
        {paid && (
          <fieldset className="checks">
            <legend>Customers pay by</legend>
            <label className="check">
              <input
                type="checkbox"
                checked={methods.includes('upi')}
                onChange={(e) => toggleMethod('upi', e.target.checked)}
              />
              <span>UPI (you verify the UTR)</span>
            </label>
            <label className="check">
              <input
                type="checkbox"
                checked={methods.includes('razorpay_link')}
                onChange={(e) => toggleMethod('razorpay_link', e.target.checked)}
              />
              <span>Razorpay payment link</span>
            </label>
            {errors.payment_methods && <p className="field__error">{errors.payment_methods}</p>}
          </fieldset>
        )}
        <label className="check">
          <input
            type="checkbox"
            checked={approval}
            onChange={(e) => setApproval(e.target.checked)}
          />
          <span>I approve each booking myself</span>
        </label>
        <label className="check">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
          <span>Show on the booking site</span>
        </label>
        <h3 className="stack__heading">Questions</h3>
        <QuestionBuilder questions={questions} onChange={setQuestions} error={errors.questions} />
        <div className="form-actions form-actions--sticky">
          <Button type="submit" disabled={save.isPending}>
            Save session
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
        </div>
      </form>
    </Modal>
  );
}

const HIGHLIGHTS = ['Most popular', 'New', 'Best value', 'Recommended', 'Limited spots'];
const OWN = '__own__';

/** A ready-made label, your own (up to 24 characters), or none. */
function HighlightField({
  value,
  onChange,
  error,
}: {
  value: string;
  onChange: (value: string) => void;
  error?: string;
}) {
  const [own, setOwn] = useState(value !== '' && !HIGHLIGHTS.includes(value));
  return (
    <div className="form-row">
      <Field
        label="Highlight"
        hint="A small label on the booking site."
        error={own ? undefined : error}
      >
        <select
          className="input"
          value={own ? OWN : value}
          onChange={(e) => {
            const next = e.target.value;
            setOwn(next === OWN);
            onChange(next === OWN ? '' : next);
          }}
        >
          <option value="">None</option>
          {HIGHLIGHTS.map((h) => (
            <option key={h} value={h}>
              {h}
            </option>
          ))}
          <option value={OWN}>Your own words…</option>
        </select>
      </Field>
      {own && (
        <Field label="Your label" error={error}>
          <input
            className="input"
            maxLength={24}
            value={value}
            onChange={(e) => onChange(e.target.value)}
          />
        </Field>
      )}
    </div>
  );
}
