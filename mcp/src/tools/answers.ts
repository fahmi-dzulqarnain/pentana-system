/**
 * Client-side check of registration answers, mirroring ActivityController@register:
 *   required checkbox -> Laravel `accepted`; any other required question -> Laravel `required`.
 * On top of that (stricter than the API, which silently drops or stores them):
 *   - answers to unknown question keys are rejected, so the assistant never believes it answered
 *     a question that the API ignored;
 *   - a dropdown answer must be one of its choices.
 * The API stays the final authority; this only stops obviously bad requests early.
 */
import type { RegistrationAnswers } from '../api.js';
import type { ActivityQuestion } from '../schemas.js';

const TRUE_WORDS = ['true', '1', 'yes', 'on'];
const FALSE_WORDS = ['false', '0', 'no', 'off', ''];

export type AnswerCheck = { ok: true; answers: RegistrationAnswers } | { ok: false; problems: string[] };

function name(q: ActivityQuestion): string {
  return `"${q.label || q.key}" (key ${q.key})`;
}

export function checkAnswers(questions: readonly ActivityQuestion[], given: RegistrationAnswers): AnswerCheck {
  const problems: string[] = [];
  const answers: RegistrationAnswers = {};
  const known = new Set(questions.map((q) => q.key));

  for (const key of Object.keys(given)) {
    if (!known.has(key)) {
      problems.push(
        known.size === 0
          ? `This activity has no registration questions, but an answer was given for "${key}".`
          : `Unknown question key "${key}". Valid keys: ${[...known].join(', ')}.`,
      );
    }
  }

  for (const q of questions) {
    const value = Object.hasOwn(given, q.key) ? given[q.key] : undefined;

    if (q.type === 'checkbox') {
      let checked: boolean | undefined;
      if (typeof value === 'boolean') checked = value;
      else if (typeof value === 'string') {
        const word = value.trim().toLowerCase();
        if (TRUE_WORDS.includes(word)) checked = true;
        else if (FALSE_WORDS.includes(word)) checked = false;
        else {
          problems.push(`${name(q)} is a checkbox: answer true or false.`);
          continue;
        }
      }
      if (q.required && checked !== true) {
        problems.push(`${name(q)} must be accepted (ticked) to register. Ask the member whether they accept it.`);
        continue;
      }
      if (checked !== undefined) answers[q.key] = checked;
      continue;
    }

    if (typeof value === 'boolean') {
      problems.push(`${name(q)} expects a text answer, not true/false.`);
      continue;
    }
    const text = value?.trim() ?? '';
    if (q.required && text === '') {
      problems.push(
        `${name(q)} is required.` + (q.type === 'select' && q.options.length > 0 ? ` Choices: ${q.options.join(', ')}.` : ''),
      );
      continue;
    }
    if (q.type === 'select' && text !== '' && q.options.length > 0 && !q.options.includes(text)) {
      problems.push(`${name(q)} must be one of: ${q.options.join(', ')} (got "${text}").`);
      continue;
    }
    if (value !== undefined) answers[q.key] = text;
  }

  return problems.length > 0 ? { ok: false, problems } : { ok: true, answers };
}
