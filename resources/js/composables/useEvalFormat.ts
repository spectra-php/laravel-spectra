import type { EvalScoreSummary, JsonValue, ScoreValue } from '@/types/api';

// Formatting shared by the Evals screens: inputs and outputs as text, and
// score values and summaries as they read.
export function useEvalFormat() {
    const text = (value: JsonValue | undefined) => {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return value;
        if (typeof value === 'object' && Object.keys(value).length === 1 && typeof Object.values(value)[0] === 'string') return Object.values(value)[0] as string;
        return JSON.stringify(value);
    };

    const scoreText = (value: ScoreValue | undefined) => {
        if (value === true) return 'yes';
        if (value === false) return 'no';
        if (typeof value === 'number') return Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
        return String(value ?? '');
    };

    const scoreClass = (value: ScoreValue | undefined) => {
        if (value === true) return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        if (value === false) return 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300';
        return 'bg-gray-100 text-gray-700 dark:bg-white/6 dark:text-gray-200';
    };

    const summaryText = (summary: EvalScoreSummary | null | undefined) => {
        if (!summary) return '—';
        if (summary.type === 'boolean') return `${Math.round((summary.average ?? 0) * 1000) / 10}%`;
        if (summary.type === 'numeric') return Number(summary.average ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
        const top = Object.entries(summary.labels || {})[0];
        return top ? `${top[0]} ${top[1]}` : '—';
    };

    // The change from the baseline, for boolean and numeric scores.
    const delta = (summary: EvalScoreSummary | null | undefined, baseline: EvalScoreSummary | null | undefined) => {
        if (!summary || !baseline || summary.type === 'categorical' || summary.average === null || baseline.average === null) return null;
        const change = summary.type === 'boolean' ? (summary.average - baseline.average) * 100 : summary.average - baseline.average;
        return Math.round(change * 10) / 10;
    };

    return { text, scoreText, scoreClass, summaryText, delta };
}
