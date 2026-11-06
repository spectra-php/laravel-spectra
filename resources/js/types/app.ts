// What the app shell provides to every screen (App.vue), and what its
// composables hand around.
import type { AxiosError } from 'axios';
import type { Ref } from 'vue';

export type AlertType = 'success' | 'danger';

export type AlertState = { type: null; message: null } | { type: AlertType; message: string };

export type ShowAlert = (type: AlertType, message: string) => void;
export type SetLoading = (value: boolean) => void;
export type RefreshView = () => void;
export type AlertRef = Ref<AlertState>;

export type HttpMethod = 'get' | 'post' | 'put' | 'patch' | 'delete';

// What PeriodRangePicker and PageRangeHeader emit as `change`.
export interface PeriodRangeChange {
    period: string;
    startDate: string | null;
    endDate: string | null;
}

// The state of the RequestFilters bar. Each value is a string as it sits in the
// URL ('1'/'0' for the yes/no filters); '' or absent means no filter, and each
// screen holds only the keys it syncs. A type, not an interface, so it fits
// usePeriodQuery's Record<string, unknown>.
export type RequestFilterValues = {
    search?: string;
    provider?: string;
    model?: string;
    status?: string;
    tag?: string;
    model_type?: string;
    finish_reason?: string;
    has_tool_calls?: string;
    is_reasoning?: string;
    reasoning_effort?: string;
    trace_id?: string;
    conversation_id?: string;
    trackable_type?: string;
    trackable_id?: string;
    prompt_name?: string;
    prompt_version?: string;
    error_fingerprint?: string;
    sort_by?: string;
    sort_dir?: string;
};

export type RequestFilterKey = keyof RequestFilterValues;

// What apiRequest rethrows when a call fails. A Laravel error body has `message`.
export type ApiError = AxiosError<{ message?: string }>;

// The config the dashboard reads from window.Spectra
// (DashboardAssets::scriptVariables()).
export interface SpectraConfig {
    path: string;
    timezone: string;
    currency: string;
    currencySymbol: string;
    version: string;
    csrfToken: string | null;
}

// What a shared dashboard's page gets from its layout.
export interface SpectraViewerConfig {
    baseUrl: string;
    dataUrl: string;
    period: string;
    name: string;
    trackableName: string | null;
    sections: string[];
    showCosts: boolean;
    showMessages: boolean;
}
