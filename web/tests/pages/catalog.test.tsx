import { screen, waitFor } from '@testing-library/react';
import { fail, mockApi, ok, path, provider, renderAt, site, thesis } from '../support';

afterEach(() => vi.unstubAllGlobals());

describe('home and provider pages', () => {
  it('lists providers', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers': ok([
        provider,
        { ...provider, slug: 'second', name: 'Prof. Second Person', title: null },
      ]),
    });
    renderAt('/');

    expect(
      await screen.findByRole('heading', { name: 'Who would you like to see?' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /^Dr\. Demo,\s?Pathologist$/ })).toHaveAttribute(
      'href',
      '/p/demo',
    );
    expect(screen.getByText('SP')).toBeInTheDocument();
  });

  it('skips straight to the only provider', async () => {
    mockApi({
      'GET /api/site': ok({ ...site, single_provider: 'demo' }),
      'GET /api/providers': ok([provider]),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
    });
    renderAt('/');

    await waitFor(() => expect(path()).toBe('/p/demo'));
  });

  it('shows a provider with their sessions', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
    });
    renderAt('/p/demo');

    expect(await screen.findByRole('heading', { level: 1, name: 'Dr. Demo' })).toBeInTheDocument();
    expect(screen.getByText('Helps with research.')).toBeInTheDocument();
    const card = screen.getByRole('link', { name: /Thesis guidance/ });
    expect(card).toHaveAttribute('href', '/p/demo/thesis');
    expect(card).toHaveTextContent('₹2,999');
    expect(card).toHaveTextContent('For residents');
  });

  it('shows the label a teacher put on a session', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({
        provider,
        services: [{ ...thesis, highlight: 'Most popular' }],
      }),
    });
    renderAt('/p/demo');

    const card = (await screen.findByText('Most popular')).closest('a') as HTMLElement;
    expect(card).toHaveTextContent('Thesis guidance');
  });

  it('handles an unknown provider', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/ghost': fail(404, 'not_found', 'Not found.'),
    });
    renderAt('/p/ghost');

    expect(await screen.findByText(/This page isn.t available/)).toBeInTheDocument();
  });

  it('shows a 404 page for unknown routes', async () => {
    mockApi({ 'GET /api/site': ok(site) });
    renderAt('/nowhere');

    expect(
      await screen.findByRole('heading', { name: /This page doesn.t exist/ }),
    ).toBeInTheDocument();
  });
});
