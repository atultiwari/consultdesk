// Phone numbers as a country (with its calling code) plus the number, joined into E.164
// (+919876543210) for the API. Uses libphonenumber-js's small metadata for codes and lengths.
import {
  getCountries,
  getCountryCallingCode,
  isPossiblePhoneNumber,
  parsePhoneNumberFromString,
  type CountryCode,
} from 'libphonenumber-js/min';

export type Country = { code: CountryCode; name: string; dial: string; flag: string };

export const DEFAULT_COUNTRY: CountryCode = 'IN';
/** Shown first: the countries most bookings come from. */
const FIRST: CountryCode[] = ['IN', 'US', 'GB', 'AE', 'NP', 'BD', 'LK', 'SG', 'CA', 'AU'];

/** 🇮🇳 from "IN": two regional-indicator letters. */
function flagOf(code: string): string {
  return String.fromCodePoint(...[...code].map((c) => 0x1f1a5 + c.charCodeAt(0)));
}

let cache: Country[] | null = null;

export function countries(): Country[] {
  if (cache) return cache;
  const names = new Intl.DisplayNames(['en'], { type: 'region' });
  const all = getCountries().map((code) => ({
    code,
    name: names.of(code) ?? code,
    dial: getCountryCallingCode(code),
    flag: flagOf(code),
  }));
  const first = FIRST.flatMap((code) => all.filter((c) => c.code === code));
  const rest = all
    .filter((c) => !FIRST.includes(c.code))
    .sort((a, b) => a.name.localeCompare(b.name));
  cache = [...first, ...rest];
  return cache;
}

/** "+919876543210" → { country: 'IN', national: '9876543210' }; anything unparsable keeps the default country. */
export function splitPhone(
  value: string,
  fallback: CountryCode = DEFAULT_COUNTRY,
): { country: CountryCode; national: string } {
  const parsed = value.trim() === '' ? undefined : parsePhoneNumberFromString(value);
  if (parsed?.country) return { country: parsed.country, national: parsed.nationalNumber };
  if (parsed) {
    const match = countries().find((c) => c.dial === parsed.countryCallingCode);
    if (match) return { country: match.code, national: parsed.nationalNumber };
  }
  return { country: fallback, national: value.replace(/^\+\d{1,3}\s*/, '').replace(/\D/g, '') };
}

/** The country's code plus the digits typed, without a leading trunk 0 ("098…" → "+9198…"). */
export function joinPhone(country: CountryCode, national: string): string {
  const digits = national.replace(/\D/g, '').replace(/^0+/, '');
  return digits === '' ? '' : `+${getCountryCallingCode(country)}${digits}`;
}

/**
 * What was typed in the number box, as E.164: a full international number ("+44 20…", "0044 20…")
 * is taken as it is; anything else gets the chosen country's code.
 */
export function toE164(country: CountryCode, typed: string): string {
  const international = /^\s*(\+|00)/.exec(typed);
  if (international) {
    const digits = typed.replace(/\D/g, '').replace(/^00/, '');
    return digits === '' ? '' : `+${digits}`;
  }
  return joinPhone(country, typed);
}

/** Whether the number has a plausible length for its country (not whether it exists). */
export function isPossiblePhone(value: string): boolean {
  return value !== '' && isPossiblePhoneNumber(value);
}
