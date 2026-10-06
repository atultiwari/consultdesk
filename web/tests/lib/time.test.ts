import {
  addDays,
  canonicalTimezone,
  dayKey,
  formatDay,
  formatLongDateTime,
  formatTime,
  groupSlots,
  nextDays,
  timezoneLabel,
} from '../../src/lib/time';

const slot = (start: string, end: string) => ({ start, end });

describe('groupSlots', () => {
  it('groups by the visitor’s local day and period', () => {
    const slots = [
      slot('2026-10-07T03:30:00Z', '2026-10-07T04:30:00Z'), // 09:00 IST
      slot('2026-10-07T07:30:00Z', '2026-10-07T08:30:00Z'), // 13:00 IST
      slot('2026-10-07T12:30:00Z', '2026-10-07T13:30:00Z'), // 18:00 IST
      slot('2026-10-07T19:00:00Z', '2026-10-07T20:00:00Z'), // 00:30 IST on the 8th
    ];

    const days = groupSlots(slots, 'Asia/Kolkata');

    expect(days.map((d) => d.date)).toEqual(['2026-10-07', '2026-10-08']);
    expect(days[0].periods.map((p) => [p.period, p.slots.length])).toEqual([
      ['morning', 1],
      ['afternoon', 1],
      ['evening', 1],
    ]);
    expect(days[1].periods.map((p) => p.period)).toEqual(['morning']);
  });

  it('puts the same instant on different days for different visitors', () => {
    const late = [slot('2026-10-07T19:00:00Z', '2026-10-07T20:00:00Z')];

    expect(groupSlots(late, 'Asia/Kolkata')[0].date).toBe('2026-10-08');
    expect(groupSlots(late, 'America/New_York')[0].date).toBe('2026-10-07');
  });

  it('omits empty periods and returns nothing for no slots', () => {
    expect(groupSlots([], 'UTC')).toEqual([]);
  });
});

describe('formatting', () => {
  it('formats times and days in the given timezone', () => {
    expect(formatTime('2026-10-07T04:30:00Z', 'Asia/Kolkata')).toBe('10:00 AM');
    expect(formatTime('2026-10-07T04:30:00Z', 'Europe/London')).toBe('5:30 AM');
    expect(formatDay('2026-10-07')).toBe('Wed 7 Oct');
    expect(formatLongDateTime('2026-10-07T11:00:00Z', 'Asia/Kolkata')).toBe(
      'Wednesday 7 October, 4:30 PM',
    );
  });

  it('labels a timezone with its short name', () => {
    expect(timezoneLabel('Asia/Kolkata', new Date('2026-10-07T04:30:00Z'))).toBe(
      'Kolkata (GMT+5:30)',
    );
    expect(timezoneLabel('Europe/London', new Date('2026-10-07T04:30:00Z'))).toBe('London (GMT+1)');
  });

  it('computes local day keys and runs of days', () => {
    expect(dayKey(new Date('2026-10-07T19:00:00Z'), 'Asia/Kolkata')).toBe('2026-10-08');
    expect(nextDays('2026-10-30', 4)).toEqual([
      '2026-10-30',
      '2026-10-31',
      '2026-11-01',
      '2026-11-02',
    ]);
  });
});

describe('addDays', () => {
  it('moves backwards and forwards across month ends', () => {
    expect(addDays('2026-10-06', -1)).toBe('2026-10-05');
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
  });
});

describe('canonicalTimezone', () => {
  it('maps legacy aliases browsers still report to current names', () => {
    expect(canonicalTimezone('Asia/Calcutta')).toBe('Asia/Kolkata');
    expect(canonicalTimezone('Europe/Kiev')).toBe('Europe/Kyiv');
    expect(canonicalTimezone('Asia/Kolkata')).toBe('Asia/Kolkata');
  });
});
