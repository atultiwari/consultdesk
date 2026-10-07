import { useMutation, useQueryClient } from '@tanstack/react-query';
import { adminDownload, adminFetch } from './api';
import { signedOut } from './hooks';

/** Saves a file the browser already has, under the name the server gave it. */
function save(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export function useDownloadBackup() {
  return useMutation({
    mutationFn: async (password: string) => {
      const { blob, filename } = await adminDownload('/system/backup', {
        method: 'POST',
        json: { password },
      });
      save(blob, filename);
      return filename;
    },
  });
}

export type RestoreRequest = { file: File; password: string; confirm: string };

export function useRestoreBackup() {
  return useMutation({
    mutationFn: ({ file, password, confirm }: RestoreRequest) => {
      const form = new FormData();
      form.append('file', file);
      form.append('password', password);
      form.append('confirm', confirm);
      return adminFetch<{ restored: true; safety_backup: string }>('/system/restore', {
        method: 'POST',
        form,
      });
    },
  });
}

/** After a restore the old session no longer exists: back to sign-in. */
export function useSignInAgain() {
  const client = useQueryClient();
  return () => void signedOut(client);
}
