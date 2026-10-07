import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, path, renderAt } from '../support';
import { ADMIN, demoProvider, providerUser, signedIn, thesisService } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const providerRoutes = {
  'GET /api/admin/providers': ok([demoProvider]),
  'GET /api/admin/providers/7/services': ok([thesisService]),
  'GET /api/admin/providers/7/availability': ok([
    { id: 1, weekday: 1, start: '10:00', end: '13:00', service_id: null },
  ]),
};

describe('admin providers', () => {
  it('lets staff add a provider', async () => {
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'POST /api/admin/providers': ok(
        { ...demoProvider, id: 8, slug: 'new-teacher', name: 'New Teacher' },
        201,
      ),
      'GET /api/admin/providers/8/services': ok([]),
      'GET /api/admin/providers/8/availability': ok([]),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers`);

    expect(await screen.findByRole('link', { name: /Dr\. Demo/ })).toHaveAttribute(
      'href',
      `${ADMIN}/providers/7`,
    );
    await user.click(screen.getByRole('button', { name: 'Add provider' }));
    const dialog = screen.getByRole('dialog', { name: 'Add provider' });
    await user.type(within(dialog).getByLabelText('Name'), 'New Teacher');
    expect(within(dialog).getByLabelText('Web address')).toHaveValue('new-teacher');
    await user.click(within(dialog).getByRole('button', { name: 'Add' }));

    expect(calls.find((c) => c.method === 'POST')?.body).toMatchObject({
      name: 'New Teacher',
      slug: 'new-teacher',
    });
    await vi.waitFor(() => expect(path()).toBe(`${ADMIN}/providers/8`));
  });

  it('saves the profile and UPI details and shows field errors from the server', async () => {
    let attempt = 0;
    const calls = mockApi({
      ...signedIn(providerUser),
      ...providerRoutes,
      'PATCH /api/admin/providers/7': () =>
        attempt++ === 0
          ? fail(422, 'validation_failed', 'Check the highlighted fields.', {
              upi_vpa: 'Enter a UPI ID like name@bank.',
            })
          : ok({ ...demoProvider, upi_vpa: 'new.placeholder@okaxis' }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7`);

    const vpa = await screen.findByLabelText('UPI ID');
    await user.clear(vpa);
    await user.type(vpa, 'nonsense');
    await user.click(screen.getByRole('button', { name: 'Save profile' }));
    expect(await screen.findByText('Enter a UPI ID like name@bank.')).toBeInTheDocument();

    await user.clear(vpa);
    await user.type(vpa, 'new.placeholder@okaxis');
    await user.click(screen.getByRole('button', { name: 'Save profile' }));
    expect(await screen.findByText('Saved.')).toBeInTheDocument();
    expect(calls.filter((c) => c.method === 'PATCH').at(-1)?.body).toMatchObject({
      upi_vpa: 'new.placeholder@okaxis',
    });
    expect(screen.queryByLabelText('Web address')).not.toBeInTheDocument();
  });

  it('saves booking rules, with no daily limit when left empty', async () => {
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'PATCH /api/admin/providers/7': ok(demoProvider),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7`);

    await user.click(await screen.findByRole('tab', { name: 'Booking rules' }));
    await user.clear(screen.getByLabelText('Gap before a session'));
    await user.type(screen.getByLabelText('Gap before a session'), '15');
    await user.clear(screen.getByLabelText('Most sessions in a day'));
    await user.click(screen.getByRole('button', { name: 'Save rules' }));

    expect(await screen.findByText('Saved.')).toBeInTheDocument();
    expect(calls.at(-1)?.body).toEqual({
      rules: {
        min_notice_min: 1440,
        horizon_days: 30,
        buffer_before: 15,
        buffer_after: 10,
        slot_interval: 30,
        max_per_day: null,
      },
    });
  });

  it('builds intake questions for a session', async () => {
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'PATCH /api/admin/services/21': (init) =>
        ok({ ...thesisService, ...JSON.parse(String(init?.body)) }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7`);

    await user.click(await screen.findByRole('tab', { name: 'Sessions' }));
    await user.click(await screen.findByRole('button', { name: 'Edit Thesis guidance' }));
    const editor = screen.getByRole('dialog', { name: 'Edit session' });

    await user.click(within(editor).getByRole('button', { name: 'Add question' }));
    const questions = within(editor).getAllByRole('group', { name: /^Question \d/ });
    expect(questions).toHaveLength(2);
    await user.type(within(questions[1]).getByLabelText('Question'), 'Stage of research');
    await user.selectOptions(within(questions[1]).getByLabelText('Answer type'), 'select');
    await user.type(
      within(questions[1]).getByLabelText('Choices (one per line)'),
      'Idea{Enter}Writing',
    );
    await user.click(within(questions[1]).getByRole('button', { name: 'Move up' }));
    await user.click(within(editor).getByRole('button', { name: 'Save session' }));

    const body = calls.find((c) => c.method === 'PATCH')?.body as { questions: unknown[] };
    expect(body.questions).toEqual([
      {
        id: 'stage_of_research',
        label: 'Stage of research',
        type: 'select',
        required: false,
        options: ['Idea', 'Writing'],
      },
      { id: 'goal', label: 'Goal', type: 'textarea', required: true },
    ]);
  });

  it('reorders sessions and highlights one as most popular', async () => {
    const intro = { ...thesisService, id: 22, slug: 'intro', title: 'Intro call', sort_order: 1 };
    let list = [thesisService, intro];
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'GET /api/admin/providers/7/services': () => ok(list),
      'PUT /api/admin/providers/7/services/order': (init) => {
        const ids = JSON.parse(String(init?.body)).ids as number[];
        list = ids.map((id) => list.find((s) => s.id === id) as typeof thesisService);
        return ok(list);
      },
      'PATCH /api/admin/services/22': (init) => ok({ ...intro, ...JSON.parse(String(init?.body)) }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7`);

    await user.click(await screen.findByRole('tab', { name: 'Sessions' }));
    await user.click(await screen.findByRole('button', { name: 'Move Intro call up' }));
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ ids: [22, 21] });
    expect(await screen.findByRole('button', { name: 'Move Intro call down' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Move Intro call up' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Edit Intro call' }));
    const editor = screen.getByRole('dialog', { name: 'Edit session' });
    await user.selectOptions(within(editor).getByLabelText('Highlight'), 'Most popular');
    await user.click(within(editor).getByRole('button', { name: 'Save session' }));
    expect(calls.find((c) => c.method === 'PATCH')?.body).toMatchObject({
      highlight: 'Most popular',
    });
  });

  it('edits the weekly hours and saves them in one go', async () => {
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'PUT /api/admin/providers/7/availability': (init) =>
        ok((JSON.parse(String(init?.body)) as { rules: unknown[] }).rules),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7`);

    await user.click(await screen.findByRole('tab', { name: 'Weekly hours' }));
    const tuesday = screen.getByRole('group', { name: 'Tuesday' });
    await user.click(within(tuesday).getByRole('button', { name: 'Add hours on Tuesday' }));
    const monday = screen.getByRole('group', { name: 'Monday' });
    await user.click(within(monday).getByRole('button', { name: 'Remove 10:00–13:00' }));
    await user.click(screen.getByRole('button', { name: 'Save hours' }));

    expect(await screen.findByText('Saved.')).toBeInTheDocument();
    expect(calls.at(-1)?.body).toEqual({
      rules: [{ weekday: 2, start: '09:00', end: '17:00', service_id: null }],
    });
  });
});

describe('blocked times', () => {
  it('adds an all-day closure in the provider’s timezone and removes it', async () => {
    let blocks: unknown[] = [];
    const calls = mockApi({
      ...signedIn(providerUser),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/blocked': () => ok(blocks),
      'POST /api/admin/blocked': () => {
        blocks = [
          {
            id: 5,
            provider_id: 7,
            provider_name: 'Dr. Demo',
            start: '2026-10-09T18:30:00Z',
            end: '2026-10-10T18:30:00Z',
            all_day: true,
            reason: 'Conference',
          },
        ];
        return ok(blocks[0], 201);
      },
      'DELETE /api/admin/blocked/5': () => {
        blocks = [];
        return ok(null);
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/blocked`);

    expect(await screen.findByText('No time blocked.')).toBeInTheDocument();
    await user.type(screen.getByLabelText('From'), '2026-10-10');
    await user.type(screen.getByLabelText('To'), '2026-10-10');
    await user.type(screen.getByLabelText('Reason'), 'Conference');
    await user.click(screen.getByRole('button', { name: 'Block' }));

    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({
      provider_id: 7,
      start: '2026-10-10T00:00:00+05:30',
      end: '2026-10-11T00:00:00+05:30',
      all_day: true,
      reason: 'Conference',
    });
    expect(await screen.findByText('Conference')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Remove Conference' }));
    expect(await screen.findByText('No time blocked.')).toBeInTheDocument();
  });
});
