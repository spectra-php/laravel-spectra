import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const allowedPeriods = ['today', 'week', 'month', 'year', 'all'];

const periodLabels = {
    today: 'Today',
    week: 'This Week',
    month: 'This Month',
    year: 'This Year',
    all: 'All Time',
    custom: 'Custom Range',
};

/**
 * Composable for period/date-range query state management.
 *
 * Handles reading period + custom date range from the URL query,
 * syncing state back to the router, and building API query params.
 *
 * @param {Object} options
 * @param {string[]} [options.filterKeys] - Additional filter keys to include in query sync
 * @param {() => Record<string, string>} [options.getFilters] - Getter for current filter values
 * @param {() => { current_page: number }} [options.getPagination] - Getter for current pagination
 */
export function usePeriodQuery(options = {}) {
    const route = useRoute();
    const router = useRouter();

    const period = ref('month');
    const startDate = ref(null);
    const endDate = ref(null);

    const queryString = (value) => Array.isArray(value) ? value[0] : value;
    const isValidDate = (value) => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);

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

    const buildQueryParams = (page = null) => {
        const params = new URLSearchParams();
        if (page) params.set('page', String(page));

        const hasCustomRange = Boolean(startDate.value && endDate.value);
        params.set('period', hasCustomRange ? 'custom' : period.value);

        if (hasCustomRange) {
            params.set('start_date', startDate.value);
            params.set('end_date', endDate.value);
        }

        // Append filter keys if provided
        if (options.filterKeys && options.getFilters) {
            const filters = options.getFilters();
            for (const key of options.filterKeys) {
                if (filters[key]) params.append(key, filters[key]);
            }
        }

        return params;
    };

    const buildRouteQuery = (page = null) => {
        const query = {};

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

    const normalizeComparableQuery = (query) => {
        const normalized = {};
        for (const [key, value] of Object.entries(query)) {
            if (value === null || value === undefined || value === '') continue;
            normalized[key] = Array.isArray(value) ? value.join(',') : String(value);
        }
        return normalized;
    };

    const syncQueryToRouter = async (page = null, replace = false) => {
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
