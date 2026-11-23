/**
 * Copy text to the clipboard. The Clipboard API needs a secure context, so on a
 * dashboard served over plain http this falls back to a hidden textarea.
 * Resolves to whether the text was copied.
 */
export async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            document.body.removeChild(textarea);
        }
    }
}
