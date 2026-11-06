import type { JsonValue } from '@/types/api';

// Whether an example's input fits one text box, so a new or edited one keeps the
// shape an evaluation's run() reads: plain text (key null), or one key holding text.
// Anything else (several fields, values that aren't text) needs the JSON editor.
export const textShape = (input: JsonValue): { key: string | null } | null => {
    if (typeof input === 'string') return { key: null };

    if (input !== null && typeof input === 'object' && !Array.isArray(input)) {
        const entries = Object.entries(input);

        if (entries.length === 1 && typeof entries[0][1] === 'string') return { key: entries[0][0] };
    }

    return null;
};

// A starting point for the JSON editor: the same fields, with any text emptied.
export const blankInput = (input: JsonValue): JsonValue => {
    if (Array.isArray(input)) return [];

    if (input !== null && typeof input === 'object') {
        return Object.fromEntries(Object.entries(input).map(([key, value]) => [key, typeof value === 'string' ? '' : value]));
    }

    return input;
};
