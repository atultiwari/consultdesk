import { buildDetailsSchema, formFieldForApiField } from '../../src/lib/details';
import type { Question } from '../../src/api/types';

const questions: Question[] = [
  {
    id: 'role',
    label: 'Your role',
    type: 'select',
    required: true,
    options: ['Student', 'Doctor'],
  },
  { id: 'goal', label: 'Goal', type: 'textarea', required: true },
  { id: 'links', label: 'Links', type: 'url', required: false },
  { id: 'consent', label: 'No patient data', type: 'checkbox', required: true },
];

const valid = {
  name: 'Asha Placeholder',
  email: 'asha@example.test',
  phone: '+91 00000 00000',
  answers: { role: 'Doctor', goal: 'Feedback', links: '', consent: true },
};

describe('buildDetailsSchema', () => {
  const schema = buildDetailsSchema(questions);

  it('accepts complete details', () => {
    expect(schema.safeParse(valid).success).toBe(true);
  });

  it('reports each problem on its own field', () => {
    const result = schema.safeParse({
      name: 'Visit https://phish.example',
      email: 'nope',
      phone: 'call me',
      answers: { role: 'Pilot', goal: '', links: 'javascript:alert(1)', consent: false },
    });

    expect(result.success).toBe(false);
    const paths = result.success ? [] : result.error.issues.map((i) => i.path.join('.'));
    expect(paths.sort()).toEqual(
      [
        'answers.consent',
        'answers.goal',
        'answers.links',
        'answers.role',
        'email',
        'name',
        'phone',
      ].sort(),
    );
  });

  it('allows optional questions to be left empty', () => {
    expect(schema.safeParse({ ...valid, answers: { ...valid.answers, links: '' } }).success).toBe(
      true,
    );
  });
});

describe('formFieldForApiField', () => {
  it('maps API field paths onto form fields', () => {
    expect(formFieldForApiField('customer.email')).toBe('email');
    expect(formFieldForApiField('customer.phone')).toBe('phone');
    expect(formFieldForApiField('answers.goal')).toBe('answers.goal');
    expect(formFieldForApiField('start')).toBeNull();
  });
});
