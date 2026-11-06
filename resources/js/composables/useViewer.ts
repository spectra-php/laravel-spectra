import { ref } from 'vue';
import axios from 'axios';
import type { ApiError } from '@/types/app';

// A shared dashboard's page: what its layout says, the period every section
// shares, and its data, which comes only from its own endpoints. Viewers can't
// reach the dashboard API.
const period = ref(window.SpectraViewer?.period ?? 'month');

export function useViewer() {
    const config = window.SpectraViewer;

    const fetchData = async <T>(path: string, params: Record<string, string | number> = {}): Promise<T> => {
        try {
            const response = await axios.get<T>(`${config.dataUrl}${path}`, {
                params,
                headers: { Accept: 'application/json' },
            });

            return response.data;
        } catch (error) {
            // The session ended while the page was open: reloading goes through the login.
            if ((error as ApiError).response?.status === 401) {
                window.location.reload();
            }

            throw error;
        }
    };

    const formatWhen = (date: string | null) => {
        if (!date) return '';

        return new Date(date).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    return { config, period, fetchData, formatWhen };
}
