import { ref } from 'vue';

// The spin lasts at least this long, so a fast reload still reads as one.
const MIN_SPIN_MS = 600;

// Per-card refresh state: `refresh('costs-by-model', load)` spins that card's
// button while `load` runs. A card already refreshing ignores another click.
export function useCardRefresh() {
    const active = ref<Record<string, boolean>>({});

    const isRefreshing = (key: string) => Boolean(active.value[key]);

    const refresh = async (key: string, load: () => Promise<unknown>) => {
        if (active.value[key]) return;

        active.value = { ...active.value, [key]: true };
        const started = Date.now();

        try {
            await load();
        } finally {
            const remaining = MIN_SPIN_MS - (Date.now() - started);
            if (remaining > 0) await new Promise((resolve) => setTimeout(resolve, remaining));
            active.value = { ...active.value, [key]: false };
        }
    };

    return { isRefreshing, refresh };
}
