export function extractApiError(err: any, fallback: string): string {
  const payload = err?.error;
  const messages: string[] = [];

  if (typeof payload === 'string') {
    messages.push(payload);
  }

  if (payload?.message && typeof payload.message === 'string') {
    messages.push(payload.message);
  }

  const rawError = typeof payload?.error === 'string'
    ? payload.error
    : typeof err?.message === 'string'
      ? err.message
      : '';

  if (/compiled_marks_section_id_foreign|SQLSTATE\[23000\].*compiled_marks.*section_id/i.test(rawError)) {
    messages.push('Unable to save marks because one or more selected students do not have a section assigned in their enrollment record.');
  } else if (/SQLSTATE|Integrity constraint violation|insert into|update .* set|Cannot add or update a child row/i.test(rawError)) {
    messages.push('The record could not be saved because some related data is missing or invalid.');
  }

  if (payload?.errors && typeof payload.errors === 'object') {
    Object.entries(payload.errors).forEach(([field, value]) => {
      if (Array.isArray(value) && value.length > 0) {
        messages.push(`${field}: ${value.join(', ')}`);
      } else if (typeof value === 'string') {
        messages.push(`${field}: ${value}`);
      }
    });
  }

  return messages.length ? Array.from(new Set(messages)).join(' | ') : fallback;
}
