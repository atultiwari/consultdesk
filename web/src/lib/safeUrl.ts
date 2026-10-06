// Defence in depth: URLs from the API are only used as links or images if their scheme is expected,
// even though the API builds or validates them too.

function parse(value: string | null | undefined): URL | null {
  if (!value) return null;
  try {
    return new URL(value);
  } catch {
    return null;
  }
}

export function safeHttpsUrl(value: string | null | undefined): string | null {
  return parse(value)?.protocol === 'https:' ? (value as string) : null;
}

export function safeUpiUri(value: string | null | undefined): string | null {
  return value && /^upi:\/\/pay\?/i.test(value) ? value : null;
}

/** https images, or same-site paths (but not protocol-relative "//host"). */
export function safeImageUrl(value: string | null | undefined): string | null {
  if (!value) return null;
  if (value.startsWith('/') && !value.startsWith('//')) return value;
  return safeHttpsUrl(value);
}
