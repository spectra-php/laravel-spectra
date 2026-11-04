import axios from 'axios';
import { inject } from 'vue';

export function useHelpers() {
    const spectra = window.Spectra;
    const showAlert = inject('showAlert', () => {});

    const truncate = (string, length = 50) => {
        if (!string) return '';
        return string.length > length ? string.substring(0, length) + '...' : string;
    };

    /**
     * Format a cost given in cents, the same way everywhere:
     * $1 and up with two decimals ($1,234.56), below $1 with four ($0.0579,
     * $0.0007), and anything under $0.0001 as "<$0.0001". The exact amount
     * is in formatCurrencyExact(), which <Money> shows on hover.
     */
    const formatCurrency = (cents) => {
        const symbol = spectra.currencySymbol || '$';
        const value = Number(cents || 0) / 100;
        const sign = value < 0 ? '-' : '';
        const abs = Math.abs(value);

        if (abs === 0) return symbol + '0.00';

        if (abs < 0.0001) return sign + '<' + symbol + '0.0001';

        const decimals = Number(abs.toFixed(4)) >= 1 ? 2 : 4;

        return sign + symbol + abs.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    };

    /**
     * Format a cost given in cents with every significant digit and never in
     * e-notation ($0.00000544), for tooltips.
     */
    const formatCurrencyExact = (cents) => {
        const symbol = spectra.currencySymbol || '$';
        const value = Number(cents || 0) / 100;
        const sign = value < 0 ? '-' : '';

        return sign + symbol + Math.abs(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 10 });
    };

    /** "2026-10-01" → "Oct 1" (dates from the API are calendar days, read as UTC). */
    const formatShortDate = (date) => {
        if (!date) return '';
        const parsed = new Date(String(date).length === 10 ? date + 'T00:00:00Z' : date);
        if (Number.isNaN(parsed.getTime())) return String(date);

        return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: String(date).length === 10 ? 'UTC' : undefined });
    };

    /** 1234 → "1,234"; 1_234_567 → "1.23M" when compact. */
    const formatCompact = (num) => {
        const value = Number(num || 0);
        if (Math.abs(value) < 10_000) return value.toLocaleString('en-US');

        return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 }).format(value);
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
     * Provider colors for charts and badges — the single source of truth.
     *
     * Chart colors come from a validated, color-blind-safe categorical palette
     * (light and dark steps). Each provider owns a fixed slot, so a provider
     * keeps its color across every chart and filter. Providers beyond the
     * eight slots share the neutral "other" color.
     */
    const isDark = () => document.documentElement.classList.contains('dark');

    const seriesPalette = {
        light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
        dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
        other: { light: '#a1a1aa', dark: '#71717a' },
    };

    const providerSlots = ['openai', 'anthropic', 'google', 'xai', 'mistral', 'groq', 'openrouter', 'cohere'];

    const seriesColor = (index) => {
        const palette = isDark() ? seriesPalette.dark : seriesPalette.light;

        return palette[index] ?? (isDark() ? seriesPalette.other.dark : seriesPalette.other.light);
    };

    const providerBrandColors = Object.fromEntries(
        [...providerSlots, 'replicate', 'falai', 'elevenlabs', 'scaleway', 'ollama', 'azure'].map((slug) => [
            slug,
            { badge: 'badge-provider-' + slug },
        ]),
    );

    const providerClass = (provider) => {
        return providerBrandColors[provider]?.badge || 'badge-secondary';
    };

    const providerChartColor = (provider) => {
        const slot = providerSlots.indexOf(provider);

        return seriesColor(slot === -1 ? 99 : slot);
    };

    const apiRequest = async (method, endpoint, data = {}) => {
        const url = '/' + spectra.path + '/api' + endpoint;
        try {
            const headers = spectra.csrfToken ? { 'X-CSRF-TOKEN': spectra.csrfToken } : {};
            const response = await axios({ method, url, data, headers });
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
        formatCurrencyExact,
        formatCompact,
        formatShortDate,
        seriesColor,
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
