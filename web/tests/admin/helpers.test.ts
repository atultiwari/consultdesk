import { ApiError } from '../../src/api/client';
import { formatMoney, historyLabel, statusLabel } from '../../src/admin/format';
import { fieldErrors, orNull, questionId, slugify, timezones } from '../../src/admin/forms';
import { toDrafts, toQuestions } from '../../src/admin/questions';

describe('admin formatting', () => {
  it('formats rupees, with paise only when there are some', () => {
    expect(formatMoney(299900, 'INR')).toBe('₹2,999');
    expect(formatMoney(299950, 'INR')).toBe('₹2,999.50');
  });

  it('calls a held free booking a request', () => {
    expect(statusLabel({ status: 'held', payment_method: 'free' })).toBe('Awaiting approval');
    expect(statusLabel({ status: 'held', payment_method: 'upi' })).toBe('Awaiting payment');
  });

  it('labels history entries, including ones it does not know', () => {
    expect(historyLabel('booking.no_show')).toBe('Marked no-show');
    expect(historyLabel('booking.some_new_thing')).toBe('some new thing');
  });
});

describe('admin form helpers', () => {
  it('makes web addresses from names', () => {
    expect(slugify('  Dr. Ásha  Rao! ')).toBe('dr-asha-rao');
    expect(slugify('a'.repeat(70) + ' b')).toHaveLength(64);
  });

  it('makes unique question ids that start with a letter', () => {
    expect(questionId('Stage of research?', new Set())).toBe('stage_of_research');
    expect(questionId('Stage of research', new Set(['stage_of_research']))).toBe(
      'stage_of_research_2',
    );
    expect(questionId('2nd opinion', new Set())).toBe('q_2nd_opinion');
  });

  it('treats blank text as not set and reads field errors from API errors only', () => {
    expect(orNull('   ')).toBeNull();
    expect(orNull(' x ')).toBe('x');
    expect(
      fieldErrors(new ApiError('Bad', 422, 'validation_failed', { name: 'Required' })),
    ).toEqual({ name: 'Required' });
    expect(fieldErrors(new Error('boom'))).toEqual({});
  });

  it('keeps an unusual current timezone selectable', () => {
    expect(timezones('Asia/Kolkata')).toContain('Asia/Kolkata');
    expect(timezones('Etc/Unlisted-Zone')[0]).toBe('Etc/Unlisted-Zone');
  });

  it('round-trips questions through drafts', () => {
    const questions = [
      {
        id: 'stage',
        label: 'Stage',
        type: 'select' as const,
        required: true,
        options: ['Idea', 'Writing'],
      },
      { id: 'goal', label: 'Goal', type: 'text' as const, required: false },
    ];
    expect(toQuestions(toDrafts(questions))).toEqual(questions);
  });
});
