<?php

declare(strict_types=1);

namespace Spectra\Support\Alerts;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spectra\Mail\AlertMail;

/**
 * Sends a firing or resolved alert to the channels in `spectra.alerts.notify`:
 * email addresses, a Slack incoming webhook and a generic JSON webhook. A
 * channel that fails is reported and the others still go out.
 */
class AlertNotifier
{
    /**
     * Send the alert to every configured channel.
     */
    public function send(AlertRule $rule, ?float $value, bool $resolved): void
    {
        $dashboardUrl = url()->to(config('spectra.dashboard.path', 'spectra'));

        $recipients = $this->recipients(config('spectra.alerts.notify.mail'));

        if ($recipients !== []) {
            rescue(fn () => Mail::to($recipients)->send(new AlertMail($rule, $value, $resolved, $dashboardUrl)));
        }

        if (filled($slack = config('spectra.alerts.notify.slack_webhook_url'))) {
            rescue(fn () => Http::timeout(10)->post($slack, ['text' => $this->text($rule, $value, $resolved, $dashboardUrl)])->throw());
        }

        if (filled($webhook = config('spectra.alerts.notify.webhook_url'))) {
            rescue(fn () => Http::timeout(10)->post($webhook, [
                'alert' => $rule->name,
                'status' => $resolved ? 'resolved' : 'firing',
                'metric' => $rule->metric->value,
                'value' => $value,
                'threshold' => $rule->threshold,
                'condition' => $rule->above ? 'above' : 'below',
                'window_minutes' => $rule->windowMinutes,
                'provider' => $rule->provider,
                'model' => $rule->model,
                'dashboard_url' => $dashboardUrl,
            ])->throw());
        }
    }

    /**
     * Build the one-line message for chat: "🔴 High error rate: 23.5% (error rate above 10% over 15 minutes)".
     */
    protected function text(AlertRule $rule, ?float $value, bool $resolved, string $dashboardUrl): string
    {
        $currentValue = $value !== null ? $rule->metric->format($value) : 'not enough calls to measure';
        $prefix = $resolved ? '✅ Resolved: ' : '🔴 ';

        return "{$prefix}{$rule->name}: {$currentValue} ({$rule->describe()}) <{$dashboardUrl}|Open Spectra>";
    }

    /**
     * Get the email addresses from a comma-separated string or a list.
     *
     * @return array<int, string>
     */
    protected function recipients(mixed $setting): array
    {
        $addresses = is_string($setting) ? Str::of($setting)->explode(',')->all() : Arr::wrap($setting);

        return collect($addresses)
            ->map(fn (mixed $address) => is_string($address) ? Str::trim($address) : '')
            ->filter()
            ->values()
            ->all();
    }
}
