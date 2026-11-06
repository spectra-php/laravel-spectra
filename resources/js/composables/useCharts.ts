import Chart from 'chart.js/auto';
import type { ChartOptions, ScriptableContext, TooltipCallbacks, UpdateMode } from 'chart.js';

interface SeriesData {
    labels: string[];
    values: number[];
}

interface PartsData {
    // A slice's label can be empty: a row with no provider has no display name.
    labels: (string | null | undefined)[];
    values: number[];
    colors?: string[] | (() => string[]);
}

interface SeriesChartOptions<Type extends 'line' | 'bar'> {
    label?: string;
    yTicks?: Record<string, unknown>;
    tooltipLabel?: (value: number) => string;
    chartOptions?: ChartOptions<Type>;
}

interface BarChartOptions extends SeriesChartOptions<'bar'> {
    barThickness?: number | 'flex';
}

interface DoughnutChartOptions {
    type?: 'doughnut' | 'pie';
    legend?: boolean;
    tooltipCallbacks?: Partial<TooltipCallbacks<'doughnut'>>;
    chartOptions?: ChartOptions<'doughnut'>;
}

// The parts of a chart re-theming touches, whatever its type.
export interface ThemedChart {
    canvas: HTMLCanvasElement | null;
    update(mode?: UpdateMode): void;
    destroy(): void;
}

/*
 * Chart styling for the dashboard.
 *
 * - Series colors come from a validated, color-blind-safe categorical palette
 *   with separate light and dark steps (see useHelpers::seriesColor).
 * - Grid and axes stay recessive; marks are thin (2px lines, rounded bars).
 * - Every chart re-themes itself when the dark-mode class changes, so a theme
 *   toggle never leaves light colors on a dark surface.
 */

const isDarkMode = () => document.documentElement.classList.contains('dark');

const theme = () => (isDarkMode()
    ? {
        accent: '#8b7dff',
        accentFill: ['rgba(139, 125, 255, 0.28)', 'rgba(139, 125, 255, 0)'],
        grid: 'rgba(255, 255, 255, 0.06)',
        text: '#a1a1aa',
        surface: '#121215',
        tooltipBg: 'rgba(24, 24, 27, 0.96)',
        tooltipTitle: '#fafafa',
        tooltipBody: '#d4d4d8',
        tooltipBorder: 'rgba(255, 255, 255, 0.08)',
        fallback: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
    }
    : {
        accent: '#5b45e8',
        accentFill: ['rgba(91, 69, 232, 0.16)', 'rgba(91, 69, 232, 0)'],
        grid: 'rgba(24, 24, 27, 0.06)',
        text: '#71717a',
        surface: '#ffffff',
        tooltipBg: 'rgba(255, 255, 255, 0.98)',
        tooltipTitle: '#18181b',
        tooltipBody: '#52525b',
        tooltipBorder: 'rgba(24, 24, 27, 0.08)',
        fallback: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
    });

Chart.defaults.font.family = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";
Chart.defaults.font.size = 11;

const tooltip = () => {
    const t = theme();

    return {
        backgroundColor: t.tooltipBg,
        titleColor: t.tooltipTitle,
        bodyColor: t.tooltipBody,
        borderColor: t.tooltipBorder,
        borderWidth: 1,
        cornerRadius: 10,
        padding: 10,
        boxPadding: 4,
        boxWidth: 8,
        boxHeight: 8,
        usePointStyle: true,
        titleFont: { size: 12, weight: 600 },
        bodyFont: { size: 12 },
        displayColors: true,
    };
};

const axes = (yTicks: Record<string, unknown> = {}) => {
    const t = theme();

    return {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: t.text, maxRotation: 0, autoSkipPadding: 16 },
        },
        y: {
            beginAtZero: true,
            grid: { color: t.grid, drawTicks: false },
            border: { display: false },
            ticks: { color: t.text, padding: 8, maxTicksLimit: 5, ...yTicks },
        },
    };
};

// Charts re-theme on dark-mode toggles.
const liveCharts = new Set<{ chart: ThemedChart; retheme: () => void }>();

if (typeof window !== 'undefined' && typeof MutationObserver !== 'undefined') {
    new MutationObserver(() => {
        for (const entry of liveCharts) {
            if (!entry.chart.canvas?.isConnected) {
                liveCharts.delete(entry);
                continue;
            }
            entry.retheme();
            entry.chart.update('none');
        }
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}

const track = <C extends ThemedChart>(chart: C, retheme: () => void): C => {
    const entry = { chart, retheme };
    liveCharts.add(entry);

    const destroy = chart.destroy.bind(chart);
    chart.destroy = () => {
        liveCharts.delete(entry);
        destroy();
    };

    return chart;
};

const resolveColors = (colors: PartsData['colors'], count: number) => {
    const value = typeof colors === 'function' ? colors() : colors;

    return value || theme().fallback.slice(0, count);
};

const areaFill = (context: ScriptableContext<'line'>) => {
    const { ctx, chartArea } = context.chart;
    const [top, bottom] = theme().accentFill;

    if (!chartArea) return top;

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, top);
    gradient.addColorStop(1, bottom);

    return gradient;
};

export function useCharts() {
    /** Tooltip callbacks for a chart whose option `tooltipLabel` formats each value. */
    const labelCallbacks = (tooltipLabel?: (value: number) => string) => (tooltipLabel ? { callbacks: { label: (context: { raw: unknown }) => ' ' + tooltipLabel(context.raw as number) } } : {});

    /**
     * Line/area chart for one time series.
     */
    const createLineChart = (canvas: HTMLCanvasElement, data: SeriesData, options: SeriesChartOptions<'line'> = {}) => {
        const chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: options.label || 'Value',
                    data: data.values,
                    borderColor: theme().accent,
                    backgroundColor: areaFill,
                    borderWidth: 2,
                    tension: 0.35,
                    fill: true,
                    pointRadius: 0,
                    pointHitRadius: 12,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: theme().accent,
                    pointHoverBorderColor: theme().surface,
                    pointHoverBorderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                animation: { duration: 500, easing: 'easeOutQuart' },
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false }, tooltip: { ...tooltip(), ...labelCallbacks(options.tooltipLabel) } },
                scales: axes(options.yTicks),
                ...options.chartOptions,
            },
        });

        return track(chart, () => {
            const t = theme();
            const dataset = chart.data.datasets[0];
            dataset.borderColor = t.accent;
            dataset.pointHoverBackgroundColor = t.accent;
            dataset.pointHoverBorderColor = t.surface;
            chart.options.plugins!.tooltip = { ...chart.options.plugins!.tooltip, ...tooltip() };
            Object.assign(chart.options.scales!.x!.ticks!, { color: t.text });
            Object.assign(chart.options.scales!.y!.ticks!, { color: t.text });
            chart.options.scales!.y!.grid!.color = t.grid;
        });
    };

    /**
     * Bar chart for one series.
     */
    const createBarChart = (canvas: HTMLCanvasElement, data: SeriesData, options: BarChartOptions = {}) => {
        const chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: options.label || 'Value',
                    data: data.values,
                    backgroundColor: theme().accent,
                    hoverBackgroundColor: theme().accent,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    barThickness: options.barThickness || 'flex',
                    maxBarThickness: 28,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                animation: { duration: 500, easing: 'easeOutQuart' },
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false }, tooltip: { ...tooltip(), ...labelCallbacks(options.tooltipLabel) } },
                scales: axes(options.yTicks),
                ...options.chartOptions,
            },
        });

        return track(chart, () => {
            const t = theme();
            chart.data.datasets[0].backgroundColor = t.accent;
            chart.data.datasets[0].hoverBackgroundColor = t.accent;
            chart.options.plugins!.tooltip = { ...chart.options.plugins!.tooltip, ...tooltip() };
            Object.assign(chart.options.scales!.x!.ticks!, { color: t.text });
            Object.assign(chart.options.scales!.y!.ticks!, { color: t.text });
            chart.options.scales!.y!.grid!.color = t.grid;
        });
    };

    /**
     * Doughnut for part-to-whole with a few categories. A 2px surface-colored
     * border keeps segments visually separate; the legend lists every slice.
     *
     * `data.colors` may be an array or a function returning one (re-evaluated
     * on theme change).
     */
    const createDoughnutChart = (canvas: HTMLCanvasElement, data: PartsData, options: DoughnutChartOptions = {}) => {
        const chart = new Chart(canvas, {
            type: (options.type || 'doughnut') as 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: resolveColors(data.colors, data.values.length),
                    borderColor: theme().surface,
                    borderWidth: 2,
                    hoverOffset: 4,
                    borderRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                animation: { duration: 500, easing: 'easeOutQuart' },
                cutout: options.type === 'pie' ? 0 : '72%',
                plugins: {
                    legend: {
                        display: options.legend !== false,
                        position: 'bottom',
                        labels: {
                            color: theme().text,
                            padding: 14,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 7,
                            boxHeight: 7,
                            font: { size: 12 },
                        },
                    },
                    tooltip: { ...tooltip(), callbacks: options.tooltipCallbacks || {} },
                },
                ...options.chartOptions,
            },
        });

        return track(chart, () => {
            const t = theme();
            const dataset = chart.data.datasets[0];
            dataset.backgroundColor = resolveColors(data.colors, dataset.data.length);
            dataset.borderColor = t.surface;
            chart.options.plugins!.legend!.labels!.color = t.text;
            chart.options.plugins!.tooltip = { ...chart.options.plugins!.tooltip, ...tooltip() };
        });
    };

    return {
        createLineChart,
        createBarChart,
        createDoughnutChart,
        theme,
    };
}
