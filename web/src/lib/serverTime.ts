// Countdowns use the server's clock, so a phone set a few minutes wrong still shows the real hold.

let offsetMs = 0;

/** Called with each API response's Date header. */
export function recordServerDate(header: string | null): void {
  const serverMs = header ? Date.parse(header) : Number.NaN;
  if (!Number.isNaN(serverMs)) offsetMs = serverMs - Date.now();
}

export function serverNow(): Date {
  return new Date(Date.now() + offsetMs);
}
