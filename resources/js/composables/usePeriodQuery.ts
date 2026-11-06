import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { LocationQuery, LocationQueryValue } from 'vue-router';

const allowedPeriods = ['today', 'week', 'month', 'year', 'all'];

const periodLabels: Record<string, string> = {
    today: 'Today',
    week: 'This Week',
    month: 'This Month',
    year: 'This Year',
    all: 'All Time',
    custom: 'Custom Range',
};

interface PeriodQueryOptions {
    /** Additional filter keys to include in query sync. */
    filterKeys?: readonly string[];
    /** Getter for current filter values. */
    getFilters?: () => Record<string, unknown>;
}

/**
 * Composable for period/date-range query state management.
 *
 * Handles reading period + custom date range from the URL query,
 * syncing state back to the router, and building API query params.
 */
export function usePeriodQuery(options: PeriodQueryOptions = {}) {
    const route = useRoute();
    const router = useRouter();

    const period = ref<string>('month');
    const startDate = ref<string | null>(null);
    const endDate = ref<string | null>(null);

    const queryString = (value: LocationQueryValue | LocationQueryValue[] | undefined) => Array.isArray(value) ? value[0] : value;
    const isValidDate = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);

    const periodLabel = computed(() => {
        if (startDate.value && endDate.value) {
            return `${startDate.value} to ${endDate.value}`;
        }
        return periodLabels[period.value] || period.value;
    });

    const applyQueryState = () => {
        const queryPeriod = queryString(route.query.period);
        period.value = typeof queryPeriod === 'string' && allowedPeriods.includes(queryPeriod)
            ? queryPeriod
            : 'month';

        const queryStart = queryString(route.query.start_date);
        const queryEnd = queryString(route.query.end_date);

        if (isValidDate(queryStart) && isValidDate(queryEnd)) {
            startDate.value = queryStart;
            endDate.value = queryEnd;
        } else {
            startDate.value = null;
            endDate.value = null;
        }
    };

    const buildQueryParams = (page: number | null = null) => {
        const params = new URLSearchParams();
        if (page) params.set('page', String(page));

        const hasCustomRange = Boolean(startDate.value && endDate.value);
        params.set('period', hasCustomRange ? 'custom' : period.value);

        if (hasCustomRange) {
            params.set('start_date', startDate.value as string);
            params.set('end_date', endDate.value as string);
        }

        // Set filter keys if provided (single-valued, so set() not append())
        if (options.filterKeys && options.getFilters) {
            const filters = options.getFilters();
            for (const key of options.filterKeys) {
                if (filters[key]) params.set(key, filters[key] as string);
            }
        }

        return params;
    };

    const buildRouteQuery = (page: number | null = null) => {
        const query: Record<string, unknown> = {};

        // Include filter keys if provided
        if (options.filterKeys && options.getFilters) {
            const filters = options.getFilters();
            for (const key of options.filterKeys) {
                if (filters[key]) query[key] = filters[key];
            }
        }

        if (page && page > 1) query.page = String(page);

        query.period = period.value;

        if (startDate.value && endDate.value) {
            query.start_date = startDate.value;
            query.end_date = endDate.value;
        }

        return query;
    };

    const normalizeComparableQuery = (query: Record<string, unknown> | LocationQuery) => {
        const normalized: Record<string, string> = {};
        for (const [key, value] of Object.entries(query)) {
            if (value === null || value === undefined || value === '') continue;
            normalized[key] = Array.isArray(value) ? value.join(',') : String(value);
        }
        return normalized;
    };

    const syncQueryToRouter = async (page: number | null = null, replace = false) => {
        const target = normalizeComparableQuery(buildRouteQuery(page));
        const current = normalizeComparableQuery(route.query);

        if (JSON.stringify(current) === JSON.stringify(target)) return;
        if (replace) {
            await router.replace({ query: target });
        } else {
            await router.push({ query: target });
        }
    };

    return {
        period,
        startDate,
        endDate,
        periodLabel,
        queryString,
        isValidDate,
        applyQueryState,
        buildQueryParams,
        buildRouteQuery,
        syncQueryToRouter,
    };
}
