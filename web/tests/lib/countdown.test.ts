import { remaining } from '../../src/lib/countdown';

describe('remaining', () => {
  const at = (iso: string) => new Date(iso);

  it('counts down in minutes and seconds', () => {
    expect(remaining('2026-10-05T01:00:00Z', at('2026-10-05T00:15:30Z'))).toEqual({
      expired: false,
      urgent: false,
      minutes: 44,
      seconds: 30,
      label: '44:30',
    });
  });

  it('is urgent in the last ten minutes', () => {
    expect(remaining('2026-10-05T01:00:00Z', at('2026-10-05T00:50:01Z')).urgent).toBe(true);
    expect(remaining('2026-10-05T01:00:00Z', at('2026-10-05T00:50:00Z')).urgent).toBe(false);
  });

  it('shows hours for long holds and expires at zero', () => {
    expect(remaining('2026-10-06T00:30:00Z', at('2026-10-05T00:00:00Z')).label).toBe('24:30:00');
    expect(remaining('2026-10-05T01:00:00Z', at('2026-10-05T01:00:00Z'))).toMatchObject({
      expired: true,
      label: '0:00',
    });
  });
});
