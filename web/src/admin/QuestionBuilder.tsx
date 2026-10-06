import type { QuestionType } from '../api/types';
import { Button } from '../design/components/Button';
import { newQuestion, type QuestionDraft } from './questions';

const TYPES: { value: QuestionType; label: string }[] = [
  { value: 'text', label: 'Short text' },
  { value: 'textarea', label: 'Long text' },
  { value: 'select', label: 'Choice from a list' },
  { value: 'url', label: 'Link' },
  { value: 'checkbox', label: 'Tick box (must agree)' },
];

type Props = {
  questions: QuestionDraft[];
  onChange: (questions: QuestionDraft[]) => void;
  error?: string;
};

/** Edits the intake questions a customer answers when booking. */
export function QuestionBuilder({ questions, onChange, error }: Props) {
  const update = (key: string, patch: Partial<QuestionDraft>) =>
    onChange(questions.map((q) => (q.key === key ? { ...q, ...patch } : q)));
  const move = (index: number, by: -1 | 1) => {
    const target = index + by;
    if (target < 0 || target >= questions.length) return;
    const next = [...questions];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };

  return (
    <div className="qb">
      <p className="qb__intro">
        Customers answer these when they book. Keep them few and specific.
      </p>
      {error && (
        <p className="field__error" role="alert">
          {error}
        </p>
      )}
      <ol className="qb__list">
        {questions.map((q, index) => (
          <li key={q.key}>
            <fieldset className="qb__item">
              <legend>Question {index + 1}</legend>
              <label className="qb__field">
                <span>Question</span>
                <input
                  className="input"
                  value={q.label}
                  maxLength={200}
                  onChange={(e) => update(q.key, { label: e.target.value })}
                />
              </label>
              <label className="qb__field">
                <span>Answer type</span>
                <select
                  className="input"
                  value={q.type}
                  onChange={(e) => update(q.key, { type: e.target.value as QuestionType })}
                >
                  {TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </select>
              </label>
              {q.type === 'select' && (
                <label className="qb__field qb__field--wide">
                  <span>Choices (one per line)</span>
                  <textarea
                    className="input"
                    rows={3}
                    value={q.optionsText}
                    onChange={(e) => update(q.key, { optionsText: e.target.value })}
                  />
                </label>
              )}
              <label className="check">
                <input
                  type="checkbox"
                  checked={q.required}
                  onChange={(e) => update(q.key, { required: e.target.checked })}
                />
                <span>Required</span>
              </label>
              <div className="qb__tools">
                <Button variant="ghost" onClick={() => move(index, -1)} disabled={index === 0}>
                  Move up
                </Button>
                <Button
                  variant="ghost"
                  onClick={() => move(index, 1)}
                  disabled={index === questions.length - 1}
                >
                  Move down
                </Button>
                <Button
                  variant="ghost"
                  onClick={() => onChange(questions.filter((x) => x.key !== q.key))}
                >
                  Remove
                </Button>
              </div>
            </fieldset>
          </li>
        ))}
      </ol>
      <Button variant="secondary" onClick={() => onChange([...questions, newQuestion()])}>
        Add question
      </Button>
    </div>
  );
}
