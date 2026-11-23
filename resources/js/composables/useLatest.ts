import { getCurrentScope, onScopeDispose } from 'vue';

/**
 * Keeps a slow answer from overwriting a newer one.
 *
 * A screen that reloads when a filter, the period or the route changes can have
 * two requests in flight, and the older may answer last. Each load calls the
 * function this returns and, after its `await`, asks whether it is still the
 * latest before it writes anything:
 *
 *     const begin = useLatest();
 *
 *     const load = async () => {
 *         const isLatest = begin();
 *         const data = await apiRequest(...);
 *         if (!isLatest()) return;
 *         rows.value = data;
 *     };
 *
 * Leaving the screen counts as a newer load, so nothing is written, drawn or
 * scheduled for a screen that is gone.
 */
export function useLatest() {
    let current = 0;

    if (getCurrentScope()) {
        onScopeDispose(() => {
            current++;
        });
    }

    return () => {
        const id = ++current;

        return () => id === current;
    };
}
