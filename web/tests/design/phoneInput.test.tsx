import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PhoneInput } from '../../src/design/components/PhoneInput';

describe('PhoneInput', () => {
  it('hands back an old number without a country code in the full form', () => {
    const onChange = vi.fn();
    render(<PhoneInput value="98765 43210" onChange={onChange} />);

    expect(onChange).toHaveBeenCalledWith('+919876543210');
  });

  it('shows a number set from outside, and picks a country for a pasted international number', async () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <PhoneInput value="+919876543210" onChange={onChange} aria-describedby="x" />,
    );
    rerender(<PhoneInput value="+442079460958" onChange={onChange} />);
    expect(screen.getByRole('combobox', { name: 'Country code' })).toHaveValue('GB');
    expect(screen.getByRole('textbox')).toHaveValue('2079460958');

    const user = userEvent.setup();
    await user.clear(screen.getByRole('textbox'));
    await user.type(screen.getByRole('textbox'), '+1 415 555 0123');
    await user.tab();
    expect(onChange).toHaveBeenLastCalledWith('+14155550123');
    expect(screen.getByRole('combobox', { name: 'Country code' })).toHaveValue('US');
  });
});
