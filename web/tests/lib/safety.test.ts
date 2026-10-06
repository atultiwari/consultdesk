import { safeHttpsUrl, safeImageUrl, safeUpiUri } from '../../src/lib/safeUrl';
import { serverNow, recordServerDate } from '../../src/lib/serverTime';

describe('URL allowlists', () => {
  it('only lets https links through', () => {
    expect(safeHttpsUrl('https://meet.google.com/abc-defg-hij')).toBe(
      'https://meet.google.com/abc-defg-hij',
    );
    expect(safeHttpsUrl('javascript:alert(1)')).toBeNull();
    expect(safeHttpsUrl('http://meet.google.com/x')).toBeNull();
    expect(safeHttpsUrl('data:text/html,hi')).toBeNull();
    expect(safeHttpsUrl(null)).toBeNull();
  });

  it('accepts only upi://pay deep links', () => {
    expect(safeUpiUri('upi://pay?pa=x%40upi&am=1.00')).toBe('upi://pay?pa=x%40upi&am=1.00');
    expect(safeUpiUri('upi://mandate?x')).toBeNull();
    expect(safeUpiUri('javascript:alert(1)')).toBeNull();
  });

  it('allows https or same-site images', () => {
    expect(safeImageUrl('/uploads/photo.webp')).toBe('/uploads/photo.webp');
    expect(safeImageUrl('https://cdn.example.test/logo.svg')).toBe(
      'https://cdn.example.test/logo.svg',
    );
    expect(safeImageUrl('//evil.example/x.png')).toBeNull();
    expect(safeImageUrl('data:image/svg+xml,<svg/>')).toBeNull();
  });
});

describe('server time', () => {
  afterEach(() => vi.useRealTimers());

  it('corrects for a device clock that is off', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-05T00:10:00Z')); // device is 10 minutes fast
    recordServerDate('Mon, 05 Oct 2026 00:00:00 GMT');

    expect(serverNow().toISOString()).toBe('2026-10-05T00:00:00.000Z');
    recordServerDate('not a date');
    expect(serverNow().toISOString()).toBe('2026-10-05T00:00:00.000Z');
  });
});
