import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { ThemeToggle } from '../app/ThemeToggle';
import { useForgotPassword, useLogin, useResetPassword } from './hooks';

const MIN_PASSWORD = 10;

function AuthCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <main className="auth" id="main">
      <div className="auth__card">
        <p className="auth__eyebrow">
          <span className="auth__mark" aria-hidden="true" />
          Admin
        </p>
        <h1 className="auth__title">{title}</h1>
        {children}
      </div>
      <div className="auth__theme">
        <ThemeToggle />
      </div>
    </main>
  );
}

export function LoginPage({ segment }: { segment: string }) {
  const login = useLogin(segment);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const submit = (event: FormEvent) => {
    event.preventDefault();
    login.mutate({ email: email.trim(), password });
  };

  return (
    <AuthCard title="Sign in">
      <form className="auth__form" onSubmit={submit} noValidate>
        {login.isError && (
          <Notice tone="danger" live>
            {login.error.message}
          </Notice>
        )}
        <Field label="Email">
          <input
            className="input"
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>
        <Field label="Password">
          <input
            className="input"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>
        <Button type="submit" block large disabled={login.isPending}>
          {login.isPending ? 'Signing in…' : 'Sign in'}
        </Button>
        <Link className="auth__link" to={`/${segment}/forgot`}>
          Forgot your password?
        </Link>
      </form>
    </AuthCard>
  );
}

export function ForgotPage({ segment }: { segment: string }) {
  const forgot = useForgotPassword(segment);
  const [email, setEmail] = useState('');

  if (forgot.isSuccess) {
    return (
      <AuthCard title="Check your email">
        <p className="auth__text">
          If that address has an account, a link to set a new password is on its way. It works for
          30 minutes.
        </p>
        <Link className="auth__link" to={`/${segment}`}>
          Back to sign in
        </Link>
      </AuthCard>
    );
  }

  return (
    <AuthCard title="Reset your password">
      <form
        className="auth__form"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          forgot.mutate(email.trim());
        }}
      >
        {forgot.isError && (
          <Notice tone="danger" live>
            {forgot.error.message}
          </Notice>
        )}
        <Field label="Email" hint="We'll email you a link to choose a new password.">
          <input
            className="input"
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>
        <Button type="submit" block large disabled={forgot.isPending}>
          Email me a link
        </Button>
        <Link className="auth__link" to={`/${segment}`}>
          Back to sign in
        </Link>
      </form>
    </AuthCard>
  );
}

export function ResetPage({ segment }: { segment: string }) {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  // Keep the token in memory only, so it never lingers in history or a Referer header.
  const [token] = useState(() => params.get('token') ?? '');
  const reset = useResetPassword(segment);
  const [password, setPassword] = useState('');
  const [repeat, setRepeat] = useState('');
  const [problem, setProblem] = useState<string | null>(null);

  useEffect(() => {
    if (params.has('token')) void navigate(`/${segment}/reset`, { replace: true });
  }, [params, navigate, segment]);

  if (reset.isSuccess) {
    return (
      <AuthCard title="Password changed">
        <p className="auth__text">
          Your password has been changed and you have been signed out everywhere else.
        </p>
        <Link className="btn btn--block" to={`/${segment}`}>
          Sign in
        </Link>
      </AuthCard>
    );
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (password.length < MIN_PASSWORD) {
      setProblem(`Use at least ${MIN_PASSWORD} characters.`);
    } else if (password !== repeat) {
      setProblem('The passwords do not match.');
    } else {
      setProblem(null);
      reset.mutate({ token, password });
    }
  };

  return (
    <AuthCard title="Choose a new password">
      {token === '' ? (
        <Notice tone="warn" title="This link is incomplete">
          <p>Open the link from the email again, or ask for a new one.</p>
          <Link to={`/${segment}/forgot`}>Ask for a new link</Link>
        </Notice>
      ) : (
        <form className="auth__form" onSubmit={submit} noValidate>
          {(problem ?? reset.error?.message) && (
            <Notice tone="danger" live>
              {problem ?? reset.error?.message}
            </Notice>
          )}
          <Field
            label="New password"
            hint={`At least ${MIN_PASSWORD} characters. A short phrase works well.`}
          >
            <input
              className="input"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </Field>
          <Field label="Repeat it">
            <input
              className="input"
              type="password"
              autoComplete="new-password"
              value={repeat}
              onChange={(e) => setRepeat(e.target.value)}
            />
          </Field>
          <Button type="submit" block large disabled={reset.isPending}>
            Set password
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
