/** "Dr. Asha Rao" → "AR": up to two initials, ignoring honorifics. */
export function initials(name: string): string {
  return name
    .replace(/^(dr|prof|mr|ms|mrs)\.?\s+/i, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('');
}
