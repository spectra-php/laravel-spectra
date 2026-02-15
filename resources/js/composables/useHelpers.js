import axios from 'axios';
import { inject } from 'vue';

export function useHelpers() {
    const spectra = window.Spectra;
    const showAlert = inject('showAlert', () => {});

    const truncate = (string, length = 50) => {
        if (!string) return '';
        return string.length > length ? string.substring(0, length) + '...' : string;
    };

    const formatCurrency = (cents, decimals = 6) => {
        const symbol = spectra.currencySymbol || '$';
        if (cents === null || cents === undefined) return symbol + '0.00';
        const isNegative = cents < 0;
        const absDollars = Math.abs(cents) / 100;
        const sign = isNegative ? '-' : '';
        // For very small amounts, always show at least 6 decimal places for visibility
        if (absDollars > 0 && absDollars < 0.01) {
            return sign + symbol + absDollars.toFixed(Math.max(decimals, 6));
        }
        // For larger amounts, show 2-4 decimal places
        return sign + symbol + absDollars.toFixed(Math.min(decimals, 4));
    };

    const formatNumber = (num) => {
        if (num === null || num === undefined) return '0';
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    };

    const formatDuration = (ms) => {
        if (ms === null || ms === undefined) return '0ms';
        if (ms < 1000) return Math.round(ms) + 'ms';
        if (ms < 60000) return (ms / 1000).toFixed(2) + 's';
        return (ms / 60000).toFixed(2) + 'm';
    };

    const statusClass = (status) => {
        const classes = {
            success: 'badge-success',
            completed: 'badge-success',
            failed: 'badge-danger',
            error: 'badge-danger',
            pending: 'badge-warning',
            processing: 'badge-info',
        };
        return classes[status] || 'badge-secondary';
    };

    /**
     * Provider brand colors — single source of truth for the entire app.
     *
     * badge: CSS class for badges (defined in app.css)
     * chart: RGB string for Chart.js datasets
     */
    const providerBrandColors = {
        openai:      { badge: 'badge-provider-openai',      chart: 'rgb(16, 163, 127)' },   // #10A37F — OpenAI green
        anthropic:   { badge: 'badge-provider-anthropic',    chart: 'rgb(217, 119, 87)' },   // #D97757 — Anthropic terracotta
        google:      { badge: 'badge-provider-google',       chart: 'rgb(66, 133, 244)' },   // #4285F4 — Google blue
        xai:         { badge: 'badge-provider-xai',          chart: 'rgb(113, 113, 122)' },  // #71717A — xAI neutral
        ollama:      { badge: 'badge-provider-ollama',       chart: 'rgb(245, 245, 245)' },  // light gray — Ollama
        azure:       { badge: 'badge-provider-azure',        chart: 'rgb(0, 120, 212)' },    // #0078D4 — Azure blue
        openrouter:  { badge: 'badge-provider-openrouter',   chart: 'rgb(110, 65, 226)' },   // #6E41E2 — OpenRouter purple
        cohere:      { badge: 'badge-provider-cohere',       chart: 'rgb(57, 89, 255)' },    // #3959FF — Cohere blue
        groq:        { badge: 'badge-provider-groq',         chart: 'rgb(244, 101, 35)' },   // #F46523 — Groq orange
        elevenlabs:  { badge: 'badge-provider-elevenlabs',   chart: 'rgb(50, 50, 50)' },     // dark — ElevenLabs
        replicate:   { badge: 'badge-provider-replicate',    chart: 'rgb(50, 50, 50)' },     // dark — Replicate
        mistral:     { badge: 'badge-provider-mistral',      chart: 'rgb(255, 116, 38)' },   // #FF7426 — Mistral orange
    };

    const providerClass = (provider) => {
        return providerBrandColors[provider]?.badge || 'badge-secondary';
    };

    const providerChartColor = (provider) => {
        return providerBrandColors[provider]?.chart || 'rgb(156, 163, 175)';
    };

    const apiRequest = async (method, endpoint, data = {}) => {
        const url = '/' + spectra.path + '/api' + endpoint;
        try {
            const response = await axios({ method, url, data });
            return response.data;
        } catch (error) {
            showAlert('danger', 'API request failed: ' + (error.response?.data?.message || error.message));
            throw error;
        }
    };

    const alertSuccess = (message) => {
        showAlert('success', message);
    };

    const alertError = (message) => {
        showAlert('danger', message);
    };

    const currencySymbol = spectra.currencySymbol || '$';

    const formatDurationSeconds = (seconds) => {
        if (!seconds) return '0s';
        if (seconds < 60) return seconds.toFixed(1) + 's';
        const m = Math.floor(seconds / 60);
        const s = (seconds % 60).toFixed(0);
        return m + 'm ' + s + 's';
    };

    return {
        spectra,
        truncate,
        formatCurrency,
        currencySymbol,
        formatNumber,
        formatDuration,
        formatDurationSeconds,
        statusClass,
        providerClass,
        providerChartColor,
        providerBrandColors,
        apiRequest,
        alertSuccess,
        alertError,
    };
}
