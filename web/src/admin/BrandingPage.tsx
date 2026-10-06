import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Loading, Notice } from '../design/components/Notice';
import { safeImageUrl } from '../lib/safeUrl';
import { Link } from 'react-router';
import { useAdmin } from './context';
import { fieldErrors } from './forms';
import { useSetup } from './setupHooks';
import { useBranding, useRemoveLogo, useSaveBranding, useUploadLogo } from './settingsHooks';
import type { Branding } from './types';

const PRESETS: { value: Branding['preset']; name: string; note: string }[] = [
  { value: 'neutral', name: 'Neutral', note: 'Calm and plain, with system fonts' },
  { value: 'he', name: 'H&E', note: 'Warm paper, violet and rose, with a serif display face' },
  {
    value: 'vrl',
    name: 'Vedant Research Labs',
    note: 'Deep violet with rose, bold grotesque headings',
  },
];
const HEX = /^#[0-9a-fA-F]{6}$/;

function ColourField({
  label,
  value,
  onChange,
  error,
}: {
  label: string;
  value: string | null;
  onChange: (v: string | null) => void;
  error?: string;
}) {
  const on = value !== null;
  return (
    <div className="colour">
      <label className="check">
        <input
          type="checkbox"
          checked={on}
          onChange={(e) => onChange(e.target.checked ? '#40297a' : null)}
        />
        <span>Use my own {label.toLowerCase()}</span>
      </label>
      {on && (
        <div className="colour__row">
          <input
            type="color"
            className="colour__picker"
            aria-label={`${label} picker`}
            value={HEX.test(value) ? value : '#000000'}
            onChange={(e) => onChange(e.target.value)}
          />
          <Field label={`${label} (hex)`} error={error}>
            <input
              className="input input--short mono"
              value={value}
              maxLength={7}
              onChange={(e) => onChange(e.target.value)}
            />
          </Field>
        </div>
      )}
    </div>
  );
}

function BrandingForm({ initial }: { initial: Branding }) {
  const save = useSaveBranding();
  const [name, setName] = useState(initial.org_name);
  const [preset, setPreset] = useState(initial.preset);
  const [accent, setAccent] = useState(initial.accent);
  const [accent2, setAccent2] = useState(initial.accent_2);
  const errors = fieldErrors(save.error);
  const touch =
    <T,>(set: (v: T) => void) =>
    (v: T) => {
      save.reset();
      set(v);
    };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate({ org_name: name.trim(), preset, accent, accent_2: accent2 });
  };

  return (
    <form className="form-grid" onSubmit={submit} noValidate>
      <fieldset className="form-section">
        <legend>Name</legend>
        <Field
          label="Organisation name"
          hint="Shown in the header, emails and the browser tab."
          error={errors.org_name}
        >
          <input
            className="input"
            value={name}
            maxLength={80}
            onChange={(e) => touch(setName)(e.target.value)}
          />
        </Field>
      </fieldset>
      <fieldset className="form-section">
        <legend>Look</legend>
        <div className="presets" role="radiogroup" aria-label="Look">
          {PRESETS.map((p) => (
            <label key={p.value} className="preset" data-preset-card={p.value}>
              <input
                type="radio"
                name="preset"
                value={p.value}
                checked={preset === p.value}
                onChange={() => touch(setPreset)(p.value)}
              />
              <span className="preset__swatch" aria-hidden="true" />
              <span>
                <span className="cell-main">{p.name}</span>
                <span className="cell-sub">{p.note}</span>
              </span>
            </label>
          ))}
        </div>
        {errors.preset && <p className="field__error">{errors.preset}</p>}
      </fieldset>
      <fieldset className="form-section">
        <legend>Colours</legend>
        <p className="hint">
          Optional. They replace the look's own colours in the light theme; the dark theme keeps its
          tuned palette.
        </p>
        <ColourField
          label="Brand colour"
          value={accent}
          onChange={touch(setAccent)}
          error={errors.accent}
        />
        <ColourField
          label="Accent colour"
          value={accent2}
          onChange={touch(setAccent2)}
          error={errors.accent_2}
        />
      </fieldset>
      <div className="form-actions">
        <Button type="submit" disabled={save.isPending}>
          Save branding
        </Button>
        {save.isSuccess && (
          <span className="saved" role="status">
            Saved.
          </span>
        )}
        {save.isError && Object.keys(errors).length === 0 && (
          <Notice tone="danger" live>
            {save.error.message}
          </Notice>
        )}
      </div>
    </form>
  );
}

function LogoPanel({ logoUrl }: { logoUrl: string | null }) {
  const upload = useUploadLogo();
  const remove = useRemoveLogo();
  const src = safeImageUrl(logoUrl);
  const problem = fieldErrors(upload.error).file ?? (upload.isError ? upload.error.message : null);

  return (
    <section className="panel logo-panel" aria-labelledby="logo-h">
      <h2 id="logo-h">Logo</h2>
      {src ? (
        <img className="logo-panel__img" src={src} alt="Current logo" />
      ) : (
        <p className="hint">No logo yet: the site shows a simple mark.</p>
      )}
      <label className="btn btn--secondary file-button">
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          aria-label={src ? 'Replace logo' : 'Upload a logo'}
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) upload.mutate(file);
            e.target.value = '';
          }}
        />
        {upload.isPending ? 'Uploading…' : src ? 'Replace logo' : 'Upload a logo'}
      </label>
      {src && (
        <Button variant="ghost" onClick={() => remove.mutate()} disabled={remove.isPending}>
          Remove logo
        </Button>
      )}
      <p className="hint">
        PNG, JPEG or WebP under 2 MB. Large images are scaled down to 1200 × 400.
      </p>
      {problem && (
        <Notice tone="danger" live>
          {problem}
        </Notice>
      )}
    </section>
  );
}

function SiteModeNote() {
  const { base } = useAdmin();
  const setup = useSetup();
  if (!setup.data?.mode) return null;
  return (
    <p className="hint site-mode">
      This site is for{' '}
      <strong>{setup.data.mode === 'single' ? 'one teacher' : 'several teachers'}</strong>.{' '}
      <Link to={`${base}/setup?step=mode`}>Change</Link>
    </p>
  );
}

export function BrandingPage() {
  const branding = useBranding();
  return (
    <div className="page">
      <header className="page__head">
        <h1>Branding</h1>
        <p className="page__sub">How the booking site looks to your customers.</p>
      </header>
      <SiteModeNote />
      {branding.isPending && <Loading />}
      {branding.isError && (
        <Notice tone="danger" live>
          {branding.error.message}
        </Notice>
      )}
      {branding.data && (
        <div className="stack">
          <BrandingForm initial={branding.data} />
          <LogoPanel logoUrl={branding.data.logo_url} />
        </div>
      )}
    </div>
  );
}
