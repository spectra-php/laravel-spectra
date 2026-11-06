import cloneDeep from 'lodash/cloneDeep';
import get from 'lodash/get';
import set from 'lodash/set';
import unset from 'lodash/unset';
import type { JsonObject, JsonValue } from '@/types/api';

// A stored request body is the provider's own JSON, and each API keeps its
// messages somewhere else. These functions read a body into one list of
// messages for the editor and write the list back, leaving everything else in
// the body as it was. A message that holds more than one piece of text (an
// image, a tool call, cached blocks) is locked: it is shown and sent unchanged,
// and only the JSON tab edits it.

export type BodyShape = 'chat' | 'anthropic' | 'responses' | 'google' | 'generate';

export interface EditorMessage {
    key: number;
    role: string;
    text: string;
    locked: boolean;
    // The body's item this message came from, null for one added in the editor.
    raw: JsonValue | null;
    // Held by the body's own system field rather than its list of messages.
    isSystemField: boolean;
}

interface SettingPaths {
    temperature: string;
    maxTokens: string;
}

let nextKey = 0;

const makeMessage = (role: string, text: string | null, raw: JsonValue | null, isSystemField = false): EditorMessage => ({
    key: nextKey++,
    role,
    text: text ?? '',
    locked: text === null,
    raw,
    isSystemField,
});

export const newMessage = (role: string): EditorMessage => makeMessage(role, '', null);

const isObject = (value: unknown): value is JsonObject => typeof value === 'object' && value !== null && !Array.isArray(value);

// The text of a message's content, or null when it holds anything but one piece of text.
const contentText = (content: JsonValue | undefined): string | null => {
    if (typeof content === 'string') return content;
    if (Array.isArray(content) && content.length === 1 && isObject(content[0]) && typeof content[0].text === 'string') return content[0].text;

    return null;
};

// Content with new text, in the form it came in (a block keeps its other keys).
const withContentText = (content: JsonValue | undefined, text: string): JsonValue => {
    if (Array.isArray(content) && content.length === 1 && isObject(content[0])) return [{ ...content[0], text }];

    return text;
};

const LOCKING_KEYS = ['tool_calls', 'function_call', 'audio'];

const readListMessage = (item: JsonValue): EditorMessage => {
    if (!isObject(item)) return makeMessage('item', null, item);
    if (typeof item.role !== 'string') return makeMessage(typeof item.type === 'string' ? item.type : 'item', null, item);

    return makeMessage(item.role, LOCKING_KEYS.some((key) => key in item) ? null : contentText(item.content), item);
};

const writeListMessage = (message: EditorMessage): JsonValue => {
    if (message.locked) return message.raw;
    if (!isObject(message.raw)) return { role: message.role, content: message.text };

    return { ...message.raw, role: message.role, content: withContentText(message.raw.content, message.text) };
};

const SYSTEM_KEYS_GOOGLE = ['systemInstruction', 'system_instruction'];

const googleParts = (item: JsonObject): JsonValue | undefined => item.parts;

const readGoogleContent = (item: JsonValue): EditorMessage => {
    if (!isObject(item)) return makeMessage('item', null, item);

    return makeMessage(typeof item.role === 'string' ? item.role : 'user', contentText(googleParts(item)), item);
};

const writeGoogleContent = (message: EditorMessage): JsonValue => {
    if (message.locked) return message.raw;
    if (!isObject(message.raw)) return { role: message.role, parts: [{ text: message.text }] };

    return { ...message.raw, role: message.role, parts: withContentText(message.raw.parts, message.text) as JsonValue[] };
};

// Which shape a body has, or null when the editor can't read it (the JSON tab always can).
export const detectShape = (provider: string, endpoint: string | null, body: JsonObject): BodyShape | null => {
    const path = endpoint ?? '';

    if (provider === 'google') return Array.isArray(body.contents) ? 'google' : null;
    if (provider === 'anthropic') return Array.isArray(body.messages) ? 'anthropic' : null;
    if (path.endsWith('/responses')) return typeof body.input === 'string' || Array.isArray(body.input) ? 'responses' : null;
    if (path === '/api/generate') return typeof body.prompt === 'string' ? 'generate' : null;

    return Array.isArray(body.messages) ? 'chat' : null;
};

export const readMessages = (shape: BodyShape, body: JsonObject): EditorMessage[] => {
    const messages: EditorMessage[] = [];

    switch (shape) {
        case 'chat':
            return (body.messages as JsonValue[]).map(readListMessage);

        case 'anthropic':
            if (body.system !== undefined && body.system !== null) messages.push(makeMessage('system', contentText(body.system), body.system, true));

            return [...messages, ...(body.messages as JsonValue[]).map(readListMessage)];

        case 'responses':
            if (typeof body.instructions === 'string') messages.push(makeMessage('system', body.instructions, body.instructions, true));
            if (typeof body.input === 'string') return [...messages, makeMessage('user', body.input, null)];

            return [...messages, ...(body.input as JsonValue[]).map(readListMessage)];

        case 'google': {
            const key = SYSTEM_KEYS_GOOGLE.find((name) => isObject(body[name]));
            if (key) messages.push(makeMessage('system', contentText((body[key] as JsonObject).parts), body[key], true));

            return [...messages, ...(body.contents as JsonValue[]).map(readGoogleContent)];
        }

        case 'generate':
            if (typeof body.system === 'string') messages.push(makeMessage('system', body.system, body.system, true));

            return [...messages, makeMessage('user', body.prompt as string, null)];
    }
};

// The system prompt's text, from the system messages of a list. One message
// keeps its form; a second added in the editor is joined to it as text.
const systemTexts = (messages: EditorMessage[]): string => messages.map((message) => message.text).join('\n\n');

export const writeMessages = (shape: BodyShape, body: JsonObject, messages: EditorMessage[]): JsonObject => {
    const next = cloneDeep(body);

    switch (shape) {
        case 'chat':
            next.messages = messages.map(writeListMessage);
            break;

        case 'anthropic': {
            const system = messages.filter((message) => message.role === 'system');
            const [first] = system;
            if (!first) delete next.system;
            else if (system.length === 1 && first.locked) next.system = first.raw;
            else next.system = system.length === 1 ? withContentText(first.raw ?? undefined, first.text) : systemTexts(system);
            next.messages = messages.filter((message) => message.role !== 'system').map(writeListMessage);
            break;
        }

        case 'responses': {
            const instructions = messages.find((message) => message.isSystemField);
            const list = messages.filter((message) => !message.isSystemField);
            if (instructions) next.instructions = instructions.text;
            else delete next.instructions;
            // A plain string stays one while it is still a single user message.
            next.input = typeof body.input === 'string' && list.length === 1 && list[0]?.role === 'user' ? list[0].text : list.map(writeListMessage);
            break;
        }

        case 'google': {
            const system = messages.filter((message) => message.role === 'system');
            const [first] = system;
            const key = SYSTEM_KEYS_GOOGLE.find((name) => isObject(body[name])) ?? 'systemInstruction';
            if (!first) delete next[key];
            else if (system.length === 1 && first.locked) next[key] = first.raw;
            else next[key] = { ...(isObject(first.raw) ? first.raw : {}), parts: [{ text: systemTexts(system) }] };
            next.contents = messages.filter((message) => message.role !== 'system').map(writeGoogleContent);
            break;
        }

        case 'generate': {
            const system = messages.find((message) => message.isSystemField);
            if (system) next.system = system.text;
            else delete next.system;
            next.prompt = messages.find((message) => !message.isSystemField)?.text ?? '';
            break;
        }
    }

    return next;
};

// The roles the editor offers for a message of this shape.
export const rolesFor = (shape: BodyShape): string[] => {
    switch (shape) {
        case 'google':
            return ['system', 'user', 'model'];
        case 'anthropic':
            return ['system', 'user', 'assistant'];
        case 'generate':
            return ['system', 'user'];
        default:
            return ['system', 'developer', 'user', 'assistant', 'tool'];
    }
};

// The closest role in another provider's API, when a new prompt changes provider.
export const roleInShape = (role: string, shape: BodyShape): string => {
    if (shape === 'google' && role === 'assistant') return 'model';
    if (shape !== 'google' && role === 'model') return 'assistant';
    if ((shape === 'google' || shape === 'anthropic') && role === 'developer') return 'system';

    return role;
};

// Whether the editor can add a message: a generate body holds one prompt.
export const canAddMessages = (shape: BodyShape): boolean => shape !== 'generate';

const settingPaths = (shape: BodyShape, provider: string, body: JsonObject): SettingPaths => {
    if (shape === 'google') return { temperature: 'generationConfig.temperature', maxTokens: 'generationConfig.maxOutputTokens' };
    if (shape === 'generate' || provider === 'ollama') return { temperature: 'options.temperature', maxTokens: 'options.num_predict' };
    if (shape === 'responses') return { temperature: 'temperature', maxTokens: 'max_output_tokens' };
    if (shape === 'anthropic') return { temperature: 'temperature', maxTokens: 'max_tokens' };

    // OpenAI's newer models refuse max_tokens; others only know it.
    if (body.max_completion_tokens !== undefined || (provider === 'openai' && body.max_tokens === undefined)) return { temperature: 'temperature', maxTokens: 'max_completion_tokens' };

    return { temperature: 'temperature', maxTokens: 'max_tokens' };
};

const numberText = (value: unknown): string => (typeof value === 'number' ? String(value) : '');

export const readSettings = (shape: BodyShape, provider: string, body: JsonObject): { temperature: string; maxTokens: string } => {
    const paths = settingPaths(shape, provider, body);

    return { temperature: numberText(get(body, paths.temperature)), maxTokens: numberText(get(body, paths.maxTokens)) };
};

// A number input's v-model holds a number once it is filled, and text while it is empty.
export const writeSettings = (shape: BodyShape, provider: string, body: JsonObject, settings: { temperature: string | number; maxTokens: string | number }): JsonObject => {
    const next = cloneDeep(body);
    const paths = settingPaths(shape, provider, next);

    for (const [path, value] of [[paths.temperature, settings.temperature], [paths.maxTokens, settings.maxTokens]] as const) {
        const number = String(value).trim() === '' ? Number.NaN : Number(value);

        if (Number.isNaN(number)) unset(next, path);
        else set(next, path, number);
    }

    return next;
};
