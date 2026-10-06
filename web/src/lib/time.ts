// Times arrive from the API in UTC and are always shown in the visitor's timezone.

export type Slot = { start: string; end: string };
export type Period = 'morning' | 'afternoon' | 'evening';
export type DayGroup = { date: string; periods: { period: Period; slots: Slot[] }[] };

const PERIODS: Period[] = ['morning', 'afternoon', 'evening'];
const AFTERNOON_FROM = 12;
const EVENING_FROM = 17;

/** Legacy IANA aliases some browsers still report, mapped to the names servers expect. */
const ALIASES: Record<string, string> = {
  'Asia/Calcutta': 'Asia/Kolkata',
  'Asia/Katmandu': 'Asia/Kathmandu',
  'Asia/Rangoon': 'Asia/Yangon',
  'Asia/Saigon': 'Asia/Ho_Chi_Minh',
  'Europe/Kiev': 'Europe/Kyiv',
  'America/Buenos_Aires': 'America/Argentina/Buenos_Aires',
  'Atlantic/Faeroe': 'Atlantic/Faroe',
  'Pacific/Ponape': 'Pacific/Pohnpei',
  'Pacific/Truk': 'Pacific/Chuuk',
};

export function canonicalTimezone(timeZone: string): string {
  return ALIASES[timeZone] ?? timeZone;
}

export function visitorTimezone(): string {
  return canonicalTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
}

function parts(date: Date, timeZone: string): Record<string, string> {
  const formatted = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date);

  return Object.fromEntries(formatted.map((p) => [p.type, p.value]));
}

/** The calendar date (YYYY-MM-DD) of an instant in a timezone. */
export function dayKey(date: Date, timeZone: string): string {
  const p = parts(date, timeZone);
  return `${p.year}-${p.month}-${p.day}`;
}

function periodOf(date: Date, timeZone: string): Period {
  const hour = Number(parts(date, timeZone).hour);
  if (hour >= EVENING_FROM) return 'evening';
  return hour >= AFTERNOON_FROM ? 'afternoon' : 'morning';
}

/** Slots grouped by the visitor's local day, then Morning / Afternoon / Evening. Empty periods are left out. */
export function groupSlots(slots: Slot[], timeZone: string): DayGroup[] {
  const days = new Map<string, Map<Period, Slot[]>>();
  for (const slot of slots) {
    const start = new Date(slot.start);
    const date = dayKey(start, timeZone);
    const periods = days.get(date) ?? new Map<Period, Slot[]>();
    const period = periodOf(start, timeZone);
    periods.set(period, [...(periods.get(period) ?? []), slot]);
    days.set(date, periods);
  }

  return [...days.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, periods]) => ({
      date,
      periods: PERIODS.filter((p) => periods.has(p)).map((period) => ({
        period,
        slots: periods.get(period) ?? [],
      })),
    }));
}

export function formatTime(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-US', { timeZone, hour: 'numeric', minute: '2-digit' }).format(
    new Date(iso),
  );
}

/** A calendar date like "Wed 7 Oct" (dates are timezone-free keys). */
export function formatDay(date: string): string {
  const d = new Date(`${date}T12:00:00Z`);
  const weekday = new Intl.DateTimeFormat('en-GB', { weekday: 'short', timeZone: 'UTC' }).format(d);
  const rest = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  }).format(d);
  return `${weekday} ${rest}`;
}

/** "Wednesday 7 October, 4:30 PM" in the given timezone. */
export function formatLongDateTime(iso: string, timeZone: string): string {
  const date = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(new Date(iso));
  return `${date}, ${formatTime(iso, timeZone)}`;
}

/** "Kolkata (GMT+5:30)": the city from the IANA name plus the offset at that moment. */
export function timezoneLabel(timeZone: string, at: Date = new Date()): string {
  const offset =
    new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'shortOffset' })
      .formatToParts(at)
      .find((p) => p.type === 'timeZoneName')?.value ?? timeZone;
  const city = timeZone.split('/').pop()?.replace(/_/g, ' ') ?? timeZone;
  return `${city} (${offset})`;
}

/** A calendar date (YYYY-MM-DD) moved by a number of days, forwards or backwards. */
export function addDays(date: string, days: number): string {
  return new Date(new Date(`${date}T12:00:00Z`).getTime() + days * 86_400_000)
    .toISOString()
    .slice(0, 10);
}

/** `count` consecutive calendar dates starting at `from` (YYYY-MM-DD). */
export function nextDays(from: string, count: number): string[] {
  const start = new Date(`${from}T12:00:00Z`);
  return Array.from({ length: count }, (_, i) =>
    new Date(start.getTime() + i * 86_400_000).toISOString().slice(0, 10),
  );
}
