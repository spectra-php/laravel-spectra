import { ref, computed } from 'vue';
import { useHelpers } from './useHelpers';
import type { Provider, ProvidersResponse } from '@/types/api';

/**
 * Composable for loading and resolving provider metadata.
 *
 * Provides provider display names and chart colors
 * from the /providers API endpoint.
 */
export function useProviders() {
    const { apiRequest } = useHelpers();
    const providers = ref<Provider[]>([]);

    const loadProviders = async () => {
        try {
            const data = await apiRequest<ProvidersResponse>('get', '/providers');
            providers.value = data.providers || [];
        } catch (error) {
            providers.value = [];
        }
    };

    const providerMetaBySlug = computed(() => {
        const map: Record<string, Provider> = {};
        for (const provider of providers.value) {
            if (!provider?.internal_name) continue;
            map[provider.internal_name] = provider;
        }
        return map;
    });

    const providerDisplayName = (provider: string | null | undefined, fallback: string | null = null) => {
        return providerMetaBySlug.value[provider as string]?.display_name || fallback || provider;
    };

    return {
        providers,
        loadProviders,
        providerMetaBySlug,
        providerDisplayName,
    };
}
