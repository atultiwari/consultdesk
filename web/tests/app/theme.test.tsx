import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { applyTheme, inkOn, overrideCss } from '../../src/app/theme';
import { mockApi, ok, renderAt, site } from '../support';

afterEach(() => {
  vi.unstubAllGlobals();
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
});

describe('theme helpers', () => {
  it('picks readable ink for brand colours', () => {
    expect(inkOn('#40297a')).toBe('#ffffff');
    expect(inkOn('#f2c46b')).toBe('#111111');
  });

  it('applies admin colours to the light theme only', () => {
    expect(
      overrideCss({ ...site, preset: 'neutral', accent: '#0a7d5a', accent_2: '#b02a60' }),
    ).toBe(":root[data-mode='light']{--brand:#0a7d5a;--brand-ink:#ffffff;--accent:#b02a60;}");
    expect(overrideCss({ ...site, preset: 'neutral' })).toBe('');
  });

  it('records the chosen and the shown theme', () => {
    applyTheme('dark');
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(document.documentElement.dataset.mode).toBe('dark');
    applyTheme('system');
    expect(document.documentElement.hasAttribute('data-theme')).toBe(false);
  });
});

describe('site chrome', () => {
  it('applies the preset and name, and cycles the theme toggle', async () => {
    mockApi({
      'GET /api/site': ok({ ...site, preset: 'vrl', org_name: 'VRL Academy Bookings' }),
      'GET /api/providers': ok([]),
    });
    const user = userEvent.setup();
    renderAt('/');

    await waitFor(() => expect(document.documentElement.dataset.preset).toBe('vrl'));
    expect(document.title).toBe('VRL Academy Bookings');
    expect(await screen.findByText('No one is taking bookings right now')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Colour theme: Auto/ }));
    expect(screen.getByRole('button', { name: /Colour theme: Light/ })).toBeInTheDocument();
    expect(window.localStorage.getItem('consultdesk-theme')).toBe('light');
    await user.click(screen.getByRole('button', { name: /Colour theme: Light/ }));
    expect(document.documentElement.dataset.theme).toBe('dark');
  });
});
