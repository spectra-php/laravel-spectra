import { ref, inject, watch, onBeforeUnmount } from 'vue';
import type { SetLoading } from '@/types/app';

export function useLoading() {
    const loading = ref(false);
    const setLoading = inject<SetLoading>('setLoading', () => {});

    watch(loading, (value) => {
        setLoading(value);
    });

    onBeforeUnmount(() => {
        setLoading(false);
    });

    return loading;
}
