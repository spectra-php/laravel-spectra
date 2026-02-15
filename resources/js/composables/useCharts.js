import Chart from 'chart.js/auto';

// Color palette
const colors = {
    primary: {
        solid: 'rgb(99, 102, 241)',
        light: 'rgba(99, 102, 241, 0.1)',
        gradient: ['rgba(99, 102, 241, 0.3)', 'rgba(99, 102, 241, 0.0)'],
    },
    success: 'rgb(16, 185, 129)',
    warning: 'rgb(245, 158, 11)',
    danger: 'rgb(239, 68, 68)',
    purple: 'rgb(139, 92, 246)',
    blue: 'rgb(59, 130, 246)',
    cyan: 'rgb(6, 182, 212)',
    pink: 'rgb(236, 72, 153)',
};

// Fallback palette for charts without explicit colors (e.g. model type breakdown)
const chartPalette = [
    colors.primary.solid,
    colors.success,
    colors.warning,
    colors.danger,
    colors.purple,
    colors.blue,
    colors.cyan,
    colors.pink,
];

// Check if dark mode is active
const isDarkMode = () => document.documentElement.classList.contains('dark');

// Get theme-aware colors
const getGridColor = () => isDarkMode() ? 'rgba(255, 255, 255, 0.06)' : 'rgba(0, 0, 0, 0.06)';
const getTextColor = () => isDarkMode() ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.5)';
const getBorderColor = () => isDarkMode() ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';

// Base chart options
const getBaseOptions = () => ({
    responsive: true,
    maintainAspectRatio: true,
    animation: {
        duration: 750,
        easing: 'easeOutQuart',
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: isDarkMode() ? 'rgba(17, 24, 39, 0.95)' : 'rgba(255, 255, 255, 0.95)',
            titleColor: isDarkMode() ? '#fff' : '#111827',
            bodyColor: isDarkMode() ? 'rgba(255, 255, 255, 0.8)' : '#4b5563',
            borderColor: getBorderColor(),
            borderWidth: 1,
            cornerRadius: 8,
            padding: 12,
            boxPadding: 6,
            usePointStyle: true,
            titleFont: {
                size: 13,
                weight: '600',
            },
            bodyFont: {
                size: 12,
            },
        },
    },
});

// Create gradient for area charts
const createGradient = (ctx, chartArea) => {
    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, colors.primary.gradient[0]);
    gradient.addColorStop(1, colors.primary.gradient[1]);
    return gradient;
};

export function useCharts() {
    /**
     * Create a line/area chart for time series data
     */
    const createLineChart = (canvas, data, options = {}) => {
        const ctx = canvas.getContext('2d');

        return new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: options.label || 'Value',
                    data: data.values,
                    borderColor: colors.primary.solid,
                    backgroundColor: (context) => {
                        const chart = context.chart;
                        const { ctx, chartArea } = chart;
                        if (!chartArea) return colors.primary.light;
                        return createGradient(ctx, chartArea);
                    },
                    borderWidth: 2.5,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: colors.primary.solid,
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                }],
            },
            options: {
                ...getBaseOptions(),
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: getTextColor(),
                            font: { size: 11 },
                            maxRotation: 0,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: getGridColor(),
                            drawBorder: false,
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: getTextColor(),
                            font: { size: 11 },
                            padding: 8,
                            ...options.yTicks,
                        },
                    },
                },
                ...options.chartOptions,
            },
        });
    };

    /**
     * Create a bar chart
     */
    const createBarChart = (canvas, data, options = {}) => {
        return new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: options.label || 'Value',
                    data: data.values,
                    backgroundColor: colors.primary.solid,
                    borderRadius: 6,
                    borderSkipped: false,
                    barThickness: options.barThickness || 'flex',
                    maxBarThickness: 40,
                }],
            },
            options: {
                ...getBaseOptions(),
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: getTextColor(),
                            font: { size: 11 },
                            maxRotation: 0,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: getGridColor(),
                            drawBorder: false,
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: getTextColor(),
                            font: { size: 11 },
                            padding: 8,
                            ...options.yTicks,
                        },
                    },
                },
                ...options.chartOptions,
            },
        });
    };

    /**
     * Create a doughnut/pie chart
     */
    const createDoughnutChart = (canvas, data, options = {}) => {
        const bgColors = data.colors || chartPalette.slice(0, data.values.length);

        return new Chart(canvas, {
            type: options.type || 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: bgColors,
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                ...getBaseOptions(),
                cutout: options.type === 'pie' ? 0 : '70%',
                plugins: {
                    ...getBaseOptions().plugins,
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: getTextColor(),
                            padding: 16,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 12 },
                        },
                    },
                    tooltip: {
                        ...getBaseOptions().plugins.tooltip,
                        callbacks: options.tooltipCallbacks || {},
                    },
                },
                ...options.chartOptions,
            },
        });
    };

    return {
        createLineChart,
        createBarChart,
        createDoughnutChart,
        colors,
        chartPalette,
    };
}
