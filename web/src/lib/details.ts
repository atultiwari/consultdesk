import { isPossiblePhone } from './phone';
import { z } from 'zod';
import type { Question } from '../api/types';

// Mirrors the API's checks so most mistakes are caught before submitting; the API stays the authority.
const NAME_FORBIDDEN = /:\/\/|www\.|[<>]/i;
const TEXT_MAX: Record<Question['type'], number> = {
  text: 500,
  textarea: 5000,
  select: 500,
  url: 500,
  checkbox: 0,
};

function answerSchema(question: Question) {
  if (question.type === 'checkbox') {
    return question.required
      ? z.literal(true, { error: 'Please confirm this to continue.' })
      : z.boolean().optional();
  }

  let text = z
    .string()
    .trim()
    .max(TEXT_MAX[question.type], `Must be at most ${TEXT_MAX[question.type]} characters.`);
  if (question.required) text = text.min(1, 'This field is required.');
  if (question.type === 'select' && question.options) {
    const options = question.options;
    return text.refine(
      (v) => (!question.required && v === '') || options.includes(v),
      'Choose one of the options.',
    );
  }
  if (question.type === 'url') {
    return text.refine(
      (v) => v === '' || /^https?:\/\/\S+$/i.test(v),
      'Enter a link starting with https://.',
    );
  }
  return text;
}

export function buildDetailsSchema(questions: Question[]) {
  return z.object({
    name: z
      .string()
      .trim()
      .min(1, 'Please enter your name.')
      .max(120, 'Must be at most 120 characters.')
      .refine((v) => !NAME_FORBIDDEN.test(v), 'Enter just your name.'),
    email: z.string().trim().pipe(z.email('Enter a valid email address.')),
    phone: z
      .string()
      .trim()
      .refine(isPossiblePhone, 'Choose your country and enter your WhatsApp number.'),
    answers: z.object(Object.fromEntries(questions.map((q) => [q.id, answerSchema(q)]))),
  });
}

export type DetailsValues = {
  name: string;
  email: string;
  phone: string;
  answers: Record<string, string | boolean>;
};

/** API validation field ("customer.email", "answers.goal") → form field name, or null if not on the form. */
export function formFieldForApiField(apiField: string): string | null {
  if (apiField.startsWith('customer.')) {
    const field = apiField.slice('customer.'.length);
    return ['name', 'email', 'phone'].includes(field) ? field : null;
  }
  return apiField.startsWith('answers.') ? apiField : null;
}
