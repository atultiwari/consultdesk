import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, ok, path, renderAt, site } from '../support';
import { ADMIN, demoProvider, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const templateSets = [
  {
    key: 'general',
    label: 'Any teacher or consultant',
    description: 'Simple one-to-one sessions.',
    templates: [
      {
        key: 'general/intro-call',
        title: 'Free intro call',
        tagline: 'A short call.',
        audience: null,
        duration_min: 15,
        price_minor: 0,
        price_note: null,
        requires_approval: false,
      },
      {
        key: 'general/one-to-one',
        title: 'One-to-one session',
        tagline: 'Focused help.',
        audience: null,
        duration_min: 45,
        price_minor: 99900,
        price_note: null,
        requires_approval: false,
      },
    ],
  },
];
const fresh = { mode: null, completed: false, provider: null, template_sets: templateSets };

describe('site setup wizard', () => {
  it('sets up a one-teacher site: mode, teacher, starter sessions, done', async () => {
    let state: Record<string, unknown> = fresh;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/setup': () => ok(state),
      'PUT /api/admin/setup/mode': (init) => {
        state = { ...state, ...JSON.parse(String(init?.body)) };
        return ok(state);
      },
      'POST /api/admin/setup/teacher': () => {
        state = { ...state, provider: { ...demoProvider, name: 'Dr. Atul Tiwari' } };
        return ok(state);
      },
      'POST /api/admin/setup/sessions': () => ok(state),
      'POST /api/admin/setup/complete': () => {
        state = { ...state, completed: true };
        return ok(state);
      },
      'GET /api/admin/dashboard': ok({ to_verify: [], to_approve: [], today: [], upcoming: [] }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/setup`);

    await user.click(await screen.findByRole('radio', { name: /Just me/ }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    expect(calls.find((c) => c.path === '/api/admin/setup/mode')?.body).toEqual({ mode: 'single' });

    await user.type(await screen.findByLabelText('Name'), 'Dr. Atul Tiwari');
    await user.type(screen.getByLabelText('Title'), 'Pathologist');
    await user.click(screen.getByRole('button', { name: 'Save and continue' }));
    expect(calls.find((c) => c.path === '/api/admin/setup/teacher')?.body).toMatchObject({
      name: 'Dr. Atul Tiwari',
      title: 'Pathologist',
    });

    const oneToOne = await screen.findByRole('group', { name: 'One-to-one session' });
    await user.click(within(oneToOne).getByRole('checkbox', { name: /Offer this/ }));
    await user.clear(within(oneToOne).getByLabelText('Price (₹)'));
    await user.type(within(oneToOne).getByLabelText('Price (₹)'), '1499');
    await user.click(screen.getByRole('button', { name: 'Add 1 session' }));
    expect(calls.find((c) => c.path === '/api/admin/setup/sessions')?.body).toEqual({
      sessions: [
        {
          key: 'general/one-to-one',
          title: 'One-to-one session',
          duration_min: 45,
          price_minor: 149900,
        },
      ],
    });

    await user.click(await screen.findByRole('button', { name: 'Finish' }));
    await vi.waitFor(() => expect(path()).toBe(ADMIN));
  });

  it('lets the owner skip starter sessions', async () => {
    const state = { ...fresh, mode: 'multi', provider: demoProvider };
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/setup': ok(state),
      'POST /api/admin/setup/sessions': ok(state),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/setup?step=sessions`);

    await user.click(await screen.findByRole('button', { name: 'Skip — I’ll make my own' }));
    expect(calls.find((c) => c.path === '/api/admin/setup/sessions')?.body).toEqual({
      sessions: [],
    });
    expect(await screen.findByRole('button', { name: 'Finish' })).toBeInTheDocument();
  });

  it('asks for a price instead of quietly making a session free', async () => {
    const state = { ...fresh, mode: 'multi', provider: demoProvider };
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/setup': ok(state),
      'POST /api/admin/setup/sessions': ok(state),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/setup?step=sessions`);

    const oneToOne = await screen.findByRole('group', { name: 'One-to-one session' });
    await user.click(within(oneToOne).getByRole('checkbox', { name: /Offer this/ }));
    await user.clear(within(oneToOne).getByLabelText('Price (₹)'));
    await user.click(screen.getByRole('button', { name: 'Add 1 session' }));

    expect(await screen.findByText(/Enter a price .* for “One-to-one session”/)).toBeInTheDocument();
    expect(calls.some((c) => c.path === '/api/admin/setup/sessions')).toBe(false);
  });

  it('reminds the owner to finish setting up', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/setup': ok(fresh),
      'GET /api/admin/dashboard': ok({ to_verify: [], to_approve: [], today: [], upcoming: [] }),
    });
    renderAt(ADMIN);

    expect(await screen.findByRole('link', { name: 'Set up your site' })).toHaveAttribute(
      'href',
      `${ADMIN}/setup`,
    );
  });

  it('shows a one-teacher site as "My profile" instead of a list of teachers', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/site': ok({ ...site, mode: 'single', single_provider: 'demo' }),
      'GET /api/admin/setup': ok({
        ...fresh,
        mode: 'single',
        completed: true,
        provider: demoProvider,
      }),
      'GET /api/admin/dashboard': ok({ to_verify: [], to_approve: [], today: [], upcoming: [] }),
    });
    renderAt(ADMIN);

    const nav = await screen.findByRole('navigation', { name: 'Admin' });
    expect(await within(nav).findByRole('link', { name: 'My profile' })).toHaveAttribute(
      'href',
      `${ADMIN}/providers/7`,
    );
    expect(within(nav).queryByRole('link', { name: 'Providers' })).not.toBeInTheDocument();
  });
});
