import { describe, expect, it } from 'vitest';
import { activityQuestionSchema } from '../src/schemas.js';
import { checkAnswers } from '../src/tools/answers.js';

const questions = [
  { key: 'size', label: 'T-shirt size', type: 'select', required: true, options: ['S', 'M'] },
  { key: 'waiver', label: 'Waiver', type: 'checkbox', required: true },
  { key: 'photo', label: 'Photo consent', type: 'checkbox', required: false },
  { key: 'name', label: 'Emergency contact', type: 'text', required: true },
  { key: 'notes', label: 'Notes', type: 'textarea', required: false },
].map((q) => activityQuestionSchema.parse(q));

describe('checkAnswers', () => {
  it('accepts complete answers and normalises them', () => {
    expect(
      checkAnswers(questions, { size: 'M', waiver: 'on', photo: 'no', name: '  Contact Person  ', notes: '' }),
    ).toEqual({ ok: true, answers: { size: 'M', waiver: true, photo: false, name: 'Contact Person', notes: '' } });
  });

  it('leaves out optional questions that were not answered', () => {
    expect(checkAnswers(questions, { size: 'S', waiver: true, name: 'x' })).toEqual({
      ok: true,
      answers: { size: 'S', waiver: true, name: 'x' },
    });
  });

  it('mirrors Laravel: required -> non-blank, required checkbox -> accepted', () => {
    const result = checkAnswers(questions, { size: '', waiver: 'false', name: '   ' });
    expect(result.ok).toBe(false);
    if (result.ok) return;
    expect(result.problems).toEqual([
      '"T-shirt size" (key size) is required. Choices: S, M.',
      '"Waiver" (key waiver) must be accepted (ticked) to register. Ask the member whether they accept it.',
      '"Emergency contact" (key name) is required.',
    ]);
  });

  it('rejects unknown keys, wrong types and invalid choices', () => {
    const result = checkAnswers(questions, { size: 'XL', waiver: 'maybe', name: true, extra: 'x' });
    expect(result.ok).toBe(false);
    if (result.ok) return;
    expect(result.problems).toEqual([
      'Unknown question key "extra". Valid keys: size, waiver, photo, name, notes.',
      '"T-shirt size" (key size) must be one of: S, M (got "XL").',
      '"Waiver" (key waiver) is a checkbox: answer true or false.',
      '"Emergency contact" (key name) expects a text answer, not true/false.',
    ]);
  });

  it('rejects answers for an activity without questions', () => {
    expect(checkAnswers([], { a: 'b' })).toEqual({
      ok: false,
      problems: ['This activity has no registration questions, but an answer was given for "a".'],
    });
    expect(checkAnswers([], {})).toEqual({ ok: true, answers: {} });
  });

  it('does not treat inherited object keys as answers', () => {
    const q = [activityQuestionSchema.parse({ key: 'toString', label: 'Odd key', type: 'text', required: true })];
    expect(checkAnswers(q, {}).ok).toBe(false);
  });
});
