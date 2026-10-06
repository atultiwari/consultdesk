import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { initials } from '../lib/initials';
import { visitorTimezone } from '../lib/time';
import { isStaff, useAdmin } from './context';
import { fieldErrors, slugify, timezones } from './forms';
import { useAdminProviders, useCreateProvider } from './hooks';
import { Modal } from './Modal';

function AddProvider({ onClose }: { onClose: () => void }) {
  const { base } = useAdmin();
  const navigate = useNavigate();
  const create = useCreateProvider();
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugEdited, setSlugEdited] = useState(false);
  const [timezone, setTimezone] = useState(visitorTimezone());
  const errors = fieldErrors(create.error);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate(
      { name: name.trim(), slug, timezone },
      { onSuccess: (created) => void navigate(`${base}/providers/${created.id}`) },
    );
  };

  return (
    <Modal title="Add provider" onClose={onClose}>
      <form className="stack" onSubmit={submit} noValidate>
        {create.isError && Object.keys(errors).length === 0 && (
          <Notice tone="danger" live>
            {create.error.message}
          </Notice>
        )}
        <Field label="Name" error={errors.name}>
          <input
            className="input"
            value={name}
            onChange={(e) => {
              setName(e.target.value);
              if (!slugEdited) setSlug(slugify(e.target.value));
            }}
          />
        </Field>
        <Field
          label="Web address"
          hint={`Their booking page: /p/${slug || 'name'}`}
          error={errors.slug}
        >
          <input
            className="input mono"
            value={slug}
            onChange={(e) => {
              setSlugEdited(true);
              setSlug(e.target.value.toLowerCase());
            }}
          />
        </Field>
        <Field label="Timezone" error={errors.timezone}>
          <select className="input" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
            {timezones(timezone).map((tz) => (
              <option key={tz}>{tz}</option>
            ))}
          </select>
        </Field>
        <div className="form-actions">
          <Button type="submit" disabled={create.isPending}>
            Add
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export function ProvidersPage() {
  const { base, user } = useAdmin();
  const providers = useAdminProviders();
  const [adding, setAdding] = useState(false);

  return (
    <div className="page">
      <header className="page__head page__head--row">
        <h1>Providers</h1>
        {isStaff(user) && <Button onClick={() => setAdding(true)}>Add provider</Button>}
      </header>
      {providers.isPending && <Loading />}
      {providers.isError && (
        <Notice tone="danger" live>
          {providers.error.message}
        </Notice>
      )}
      {providers.data?.length === 0 && (
        <p className="empty">No providers yet. Add the first one.</p>
      )}
      <ul className="tiles">
        {providers.data?.map((p) => (
          <li key={p.id}>
            <Link className="tile" to={`${base}/providers/${p.id}`}>
              <span className="tile__avatar" aria-hidden="true">
                {initials(p.name)}
              </span>
              <span className="tile__body">
                <span className="tile__title">{p.name}</span>
                <span className="tile__sub">{p.title ?? `/p/${p.slug}`}</span>
              </span>
              {!p.active && <Badge>Hidden</Badge>}
            </Link>
          </li>
        ))}
      </ul>
      {adding && <AddProvider onClose={() => setAdding(false)} />}
    </div>
  );
}
