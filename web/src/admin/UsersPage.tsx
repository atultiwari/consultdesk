import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { useAdmin } from './context';
import { formatWhen } from './format';
import { fieldErrors, orNull } from './forms';
import { useAdminProviders } from './hooks';
import { Modal } from './Modal';
import { useInviteUser, useResendInvite, useUpdateUser, useUsers } from './settingsHooks';
import type { ManagedUser, Role, UserStatus } from './types';

const STATUS: Record<
  UserStatus,
  { label: string; tone?: 'success' | 'warn' | 'danger' | 'accent' }
> = {
  active: { label: 'Active', tone: 'success' },
  invited: { label: 'Invited', tone: 'accent' },
  invite_expired: { label: 'Invite expired', tone: 'warn' },
  disabled: { label: 'Disabled', tone: 'danger' },
};

const ROLES: { value: Role; label: string }[] = [
  { value: 'owner', label: 'Owner — everything, including users and settings' },
  { value: 'admin', label: 'Admin — every provider and booking' },
  { value: 'provider', label: 'Provider — only their own profile and bookings' },
];

function RoleFields({
  role,
  providerId,
  onRole,
  onProvider,
  errors,
}: {
  role: Role;
  providerId: string;
  onRole: (role: Role) => void;
  onProvider: (id: string) => void;
  errors: Record<string, string>;
}) {
  const providers = useAdminProviders();
  return (
    <>
      <Field label="Role" error={errors.role}>
        <select className="input" value={role} onChange={(e) => onRole(e.target.value as Role)}>
          {ROLES.map((r) => (
            <option key={r.value} value={r.value}>
              {r.label}
            </option>
          ))}
        </select>
      </Field>
      {role === 'provider' && (
        <Field label="Provider" error={errors.provider_id}>
          <select className="input" value={providerId} onChange={(e) => onProvider(e.target.value)}>
            <option value="">Choose…</option>
            {providers.data?.map((p) => (
              <option key={p.id} value={String(p.id)}>
                {p.name}
              </option>
            ))}
          </select>
        </Field>
      )}
    </>
  );
}

function InviteDialog({ onClose }: { onClose: () => void }) {
  const invite = useInviteUser();
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [role, setRole] = useState<Role>('provider');
  const [providerId, setProviderId] = useState('');
  const errors = fieldErrors(invite.error);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const fullName = orNull(name);
    invite.mutate(
      {
        email: email.trim(),
        ...(fullName ? { name: fullName } : {}),
        role,
        ...(role === 'provider'
          ? { provider_id: providerId === '' ? null : Number(providerId) }
          : {}),
      },
      { onSuccess: onClose },
    );
  };

  return (
    <Modal title="Invite someone" onClose={onClose}>
      <form className="stack" onSubmit={submit} noValidate>
        <p className="hint">
          They get an email with a link to choose their own password. The link works for two days.
        </p>
        {invite.isError && Object.keys(errors).length === 0 && (
          <Notice tone="danger" live>
            {invite.error.message}
          </Notice>
        )}
        <Field label="Email" error={errors.email}>
          <input
            className="input"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>
        <Field label="Name" error={errors.name}>
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
        <RoleFields
          role={role}
          providerId={providerId}
          onRole={setRole}
          onProvider={setProviderId}
          errors={errors}
        />
        <div className="form-actions">
          <Button type="submit" disabled={invite.isPending}>
            Send invite
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function EditDialog({ user, onClose }: { user: ManagedUser; onClose: () => void }) {
  const update = useUpdateUser();
  const [name, setName] = useState(user.name ?? '');
  const [role, setRole] = useState<Role>(user.role);
  const [providerId, setProviderId] = useState(user.provider ? String(user.provider.id) : '');
  const errors = fieldErrors(update.error);

  return (
    <Modal title={`Edit ${user.email}`} onClose={onClose}>
      <form
        className="stack"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          update.mutate(
            {
              id: user.id,
              body: {
                name: orNull(name),
                role,
                ...(role === 'provider'
                  ? { provider_id: providerId === '' ? null : Number(providerId) }
                  : {}),
              },
            },
            { onSuccess: onClose },
          );
        }}
      >
        <p className="hint">
          Changing someone's role signs them out, so their new access applies straight away.
        </p>
        {update.isError && Object.keys(errors).length === 0 && (
          <Notice tone="danger" live>
            {update.error.message}
          </Notice>
        )}
        <Field label="Name" error={errors.name}>
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
        <RoleFields
          role={role}
          providerId={providerId}
          onRole={setRole}
          onProvider={setProviderId}
          errors={errors}
        />
        <div className="form-actions">
          <Button type="submit" disabled={update.isPending}>
            Save
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
        </div>
      </form>
    </Modal>
  );
}

type Feedback = { tone: 'success' | 'danger'; text: string } | null;

function UserActions({
  user,
  onNotice,
}: {
  user: ManagedUser;
  onNotice: (feedback: Feedback) => void;
}) {
  const { user: me } = useAdmin();
  const resend = useResendInvite();
  const update = useUpdateUser();
  const [asking, setAsking] = useState(false);
  const [editing, setEditing] = useState(false);
  const self = user.id === me.id;

  if (asking) {
    return (
      <span className="row-ask">
        <Button
          className="btn--danger"
          onClick={() =>
            update.mutate(
              { id: user.id, body: { disabled: true } },
              { onSettled: () => setAsking(false) },
            )
          }
        >
          Yes, disable
        </Button>
        <Button variant="secondary" onClick={() => setAsking(false)}>
          Keep
        </Button>
      </span>
    );
  }

  return (
    <span className="row-actions">
      {(user.status === 'invited' || user.status === 'invite_expired') && (
        <Button
          variant="ghost"
          aria-label={`Resend invite to ${user.email}`}
          disabled={resend.isPending}
          onClick={() => {
            onNotice(null);
            resend.mutate(user.id, {
              onSuccess: () => onNotice({ tone: 'success', text: `Invite sent to ${user.email}.` }),
              onError: (e) => onNotice({ tone: 'danger', text: e.message }),
            });
          }}
        >
          Resend invite
        </Button>
      )}
      {!self && (
        <Button variant="ghost" aria-label={`Edit ${user.email}`} onClick={() => setEditing(true)}>
          Edit
        </Button>
      )}
      {!self &&
        (user.status === 'disabled' ? (
          <Button
            variant="ghost"
            aria-label={`Enable ${user.email}`}
            onClick={() => {
              onNotice(null);
              update.mutate(
                { id: user.id, body: { disabled: false } },
                { onError: (e) => onNotice({ tone: 'danger', text: e.message }) },
              );
            }}
          >
            Enable
          </Button>
        ) : (
          <Button
            variant="ghost"
            aria-label={`Disable ${user.email}`}
            onClick={() => setAsking(true)}
          >
            Disable
          </Button>
        ))}
      {editing && <EditDialog user={user} onClose={() => setEditing(false)} />}
    </span>
  );
}

export function UsersPage() {
  const users = useUsers();
  const [inviting, setInviting] = useState(false);
  const [notice, setNotice] = useState<Feedback>(null);
  const tz = visitorTimezone();

  return (
    <div className="page">
      <header className="page__head page__head--row">
        <div>
          <h1>Users</h1>
          <p className="page__sub">Who can sign in to this admin area.</p>
        </div>
        <Button onClick={() => setInviting(true)}>Invite someone</Button>
      </header>
      {notice && (
        <Notice tone={notice.tone} live>
          {notice.text}
        </Notice>
      )}
      {users.isPending && <Loading />}
      {users.isError && (
        <Notice tone="danger" live>
          {users.error.message}
        </Notice>
      )}
      {users.data && (
        <div className="table-wrap">
          <table className="table" aria-label="Users">
            <thead>
              <tr>
                <th scope="col">Person</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col">Last sign-in</th>
                <th scope="col">
                  <span className="visually-hidden">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {users.data.map((u) => (
                <tr key={u.id}>
                  <td>
                    <span className="cell-main">{u.name ?? u.email}</span>
                    {u.name && <span className="cell-sub">{u.email}</span>}
                  </td>
                  <td>
                    <span className="cell-main cap">{u.role}</span>
                    {u.provider && <span className="cell-sub">{u.provider.name}</span>}
                  </td>
                  <td>
                    <Badge tone={STATUS[u.status].tone}>{STATUS[u.status].label}</Badge>
                  </td>
                  <td>{u.last_login_at ? formatWhen(u.last_login_at, tz) : 'Never'}</td>
                  <td>
                    <UserActions user={u} onNotice={setNotice} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {inviting && <InviteDialog onClose={() => setInviting(false)} />}
    </div>
  );
}
