import { Button } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import { initials } from '../lib/initials';
import { safeImageUrl } from '../lib/safeUrl';
import { fieldErrors } from './forms';
import { usePhoto } from './settingsHooks';
import type { AdminProvider } from './types';

/** The provider's portrait: shown on their booking page. */
export function PhotoField({ provider }: { provider: AdminProvider }) {
  const photo = usePhoto(provider.id);
  const src = safeImageUrl(provider.photo_url ?? null);
  const problem = fieldErrors(photo.error).file ?? (photo.isError ? photo.error.message : null);

  return (
    <div className="photo-field">
      {src ? (
        <img className="photo-field__img" src={src} alt={provider.name} width={96} height={96} />
      ) : (
        <span className="photo-field__img photo-field__img--empty" aria-hidden="true">
          {initials(provider.name)}
        </span>
      )}
      <div className="stack">
        <label className="btn btn--secondary file-button">
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            aria-label="Upload a photo"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) photo.mutate(file);
              e.target.value = '';
            }}
          />
          {photo.isPending ? 'Uploading…' : src ? 'Replace photo' : 'Upload a photo'}
        </label>
        {src && (
          <Button variant="ghost" onClick={() => photo.mutate(null)} disabled={photo.isPending}>
            Remove photo
          </Button>
        )}
        <p className="hint">A square crop of the centre is used, up to 600 × 600.</p>
        {problem && (
          <Notice tone="danger" live>
            {problem}
          </Notice>
        )}
      </div>
    </div>
  );
}
