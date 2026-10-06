import type { Question, QuestionType } from '../api/types';
import { questionId } from './forms';

export type QuestionDraft = {
  /** Stable React key; new questions get their API id from the label when saved. */
  key: string;
  id: string | null;
  label: string;
  type: QuestionType;
  required: boolean;
  optionsText: string;
};

let nextKey = 0;
export function newQuestion(): QuestionDraft {
  nextKey += 1;
  return {
    key: `new-${nextKey}`,
    id: null,
    label: '',
    type: 'text',
    required: false,
    optionsText: '',
  };
}

export const toDrafts = (questions: Question[]): QuestionDraft[] =>
  questions.map((q) => ({
    key: q.id,
    id: q.id,
    label: q.label,
    type: q.type,
    required: q.required,
    optionsText: (q.options ?? []).join('\n'),
  }));

/** Turns drafts back into questions, naming new ones after their label. */
export function toQuestions(drafts: QuestionDraft[]): Question[] {
  const taken = new Set(drafts.flatMap((d) => (d.id ? [d.id] : [])));
  return drafts.map((d) => {
    const id = d.id ?? questionId(d.label, taken);
    taken.add(id);
    const question: Question = { id, label: d.label.trim(), type: d.type, required: d.required };
    if (d.type !== 'select') return question;
    const options = d.optionsText
      .split('\n')
      .map((o) => o.trim())
      .filter(Boolean);
    return { ...question, options };
  });
}
