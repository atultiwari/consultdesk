import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, ok, renderAt } from '../support';
import { ADMIN, demoProvider, providerUser, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const coupon = {
  id: 3,
  code: 'DIWALI25',
  provider_id: null,
  provider_name: null,
  kind: 'percent',
  value: 25,
  service_ids: null,
  valid_from: null,
  valid_until: '2026-11-15T18:29:59Z',
  max_uses: 50,
  once_per_email: true,
  active: true,
  note: 'Festival offer',
  uses: 4,
  editable: true,
};

describe('coupons', () => {
  it('lets the owner create a coupon and lists how often it is used', async () => {
    let list: unknown[] = [];
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/coupons': () => ok(list),
      'POST /api/admin/coupons': () => {
        list = [coupon];
        return ok(coupon, 201);
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/coupons`);

    expect(await screen.findByText(/No coupons yet/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'New coupon' }));
    const dialog = await screen.findByRole('dialog', { name: 'New coupon' });
    await user.type(within(dialog).getByLabelText('Code'), 'diwali25');
    await user.clear(within(dialog).getByLabelText('Percent off'));
    await user.type(within(dialog).getByLabelText('Percent off'), '25');
    await user.type(within(dialog).getByLabelText('Uses in total', { exact: false }), '50');
    await user.click(within(dialog).getByRole('button', { name: 'Create coupon' }));

    expect(
      calls.find((c) => c.path === '/api/admin/coupons' && c.method === 'POST')?.body,
    ).toMatchObject({
      code: 'DIWALI25',
      kind: 'percent',
      value: 25,
      provider_id: null,
      max_uses: 50,
      once_per_email: true,
    });
    const row = (await screen.findByText('DIWALI25')).closest('tr') as HTMLElement;
    expect(row).toHaveTextContent('25% off');
    expect(row).toHaveTextContent('4 of 50');
  });

  it('shows site-wide coupons to a teacher without letting them change them', async () => {
    mockApi({
      ...signedIn(providerUser),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/coupons': ok([{ ...coupon, editable: false }]),
    });
    renderAt(`${ADMIN}/coupons`);

    const row = (await screen.findByText('DIWALI25')).closest('tr') as HTMLElement;
    expect(within(row).getByText('Site-wide')).toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument();
  });
});
