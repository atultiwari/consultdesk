import { countries, isPossiblePhone, joinPhone, splitPhone, toE164 } from '../../src/lib/phone';

describe('phone numbers', () => {
  it('lists every country with its flag and calling code, India first', () => {
    const list = countries();
    expect(list.length).toBeGreaterThan(200);
    expect(list[0]).toEqual({ code: 'IN', name: 'India', dial: '91', flag: '🇮🇳' });
    expect(list.find((c) => c.code === 'GB')?.dial).toBe('44');
  });

  it('turns whatever people type into one form, whether they add +91, 091 or a trunk 0', () => {
    expect(joinPhone('IN', '98765 43210')).toBe('+919876543210');
    expect(joinPhone('IN', '098765 43210')).toBe('+919876543210');
    expect(toE164('IN', '+91 98765 43210')).toBe('+919876543210');
    expect(toE164('IN', '0091 98765 43210')).toBe('+919876543210');
    expect(toE164('GB', '020 7946 0958')).toBe('+442079460958');
    expect(toE164('IN', '')).toBe('');
  });

  it('splits a stored number back into country and number', () => {
    expect(splitPhone('+919876543210')).toEqual({ country: 'IN', national: '9876543210' });
    expect(splitPhone('+442079460958')).toEqual({ country: 'GB', national: '2079460958' });
    expect(splitPhone('')).toEqual({ country: 'IN', national: '' });
  });

  it('checks the length is right for the country', () => {
    expect(isPossiblePhone('+919876543210')).toBe(true);
    expect(isPossiblePhone('+91987654')).toBe(false);
    expect(isPossiblePhone('')).toBe(false);
  });
});
