import { ref, inject, watch, onBeforeUnmount } from 'vue';

export function useLoading() {
    const loading = ref(false);
    const setLoading = inject('setLoading', () => {});

    watch(loading, (value) => {
        setLoading(value);
    });

    onBeforeUnmount(() => {
        setLoading(false);
    });

    return loading;
}
