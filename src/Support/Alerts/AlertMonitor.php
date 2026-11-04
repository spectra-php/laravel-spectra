<?php

declare(strict_types=1);

namespace Spectra\Support\Alerts;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Spectra\Events\AlertResolved;
use Spectra\Events\AlertTriggered;

/**
 * Checks the alert rules: measures each one, and when one starts firing (or
 * keeps firing past its cooldown) dispatches AlertTriggered and notifies;
 * when a firing one recovers, AlertResolved. Each rule's state lives in the
 * cache, so the check can run from any server.
 */
class AlertMonitor
{
    /**
     * Create a new alert monitor instance.
     */
    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly AlertNotifier $notifier,
    ) {}

    /**
     * Get the configured rules.
     *
     * @return array<int, AlertRule>
     */
    public function rules(): array
    {
        /** @var array<int, array<string, mixed>> $rules */
        $rules = (array) config('spectra.alerts.rules', []);

        return collect($rules)
            ->map(fn (array $rule) => AlertRule::fromConfig($rule))
            ->values()
            ->all();
    }

    /**
     * Check every rule now and return what each one measured.
     *
     * @return array<int, AlertStatus>
     */
    public function check(CarbonInterface $now): array
    {
        return collect($this->rules())
            ->map(fn (AlertRule $rule) => $this->checkRule($rule, $now))
            ->all();
    }

    /**
     * Get each rule's last known state without measuring again, for the dashboard.
     *
     * @return array<int, AlertStatus>
     */
    public function statuses(CarbonInterface $now): array
    {
        return collect($this->rules())
            ->map(function (AlertRule $rule) use ($now): AlertStatus {
                $state = $this->state($rule);

                return new AlertStatus($rule, $this->evaluator->measure($rule, $now), $state['firing'], $state['since'], $state['notified_at']);
            })
            ->all();
    }

    /**
     * Measure one rule, and fire, repeat or resolve it.
     */
    protected function checkRule(AlertRule $rule, CarbonInterface $now): AlertStatus
    {
        $value = $this->evaluator->measure($rule, $now);
        $breached = $value !== null && $rule->isBreachedBy($value);
        ['firing' => $firing, 'since' => $since, 'notified_at' => $notifiedAt] = $this->state($rule);

        if ($breached) {
            $cooldown = (int) config('spectra.alerts.cooldown', 60);
            $due = ! $firing || $notifiedAt === null || $notifiedAt->copy()->addMinutes($cooldown)->lessThanOrEqualTo($now);

            if ($due) {
                event(new AlertTriggered($rule, $value));
                $this->notifier->send($rule, $value, resolved: false);
                $notifiedAt = $now;
            }

            $this->store($rule, true, $firing ? $since : $now, $notifiedAt);

            return new AlertStatus($rule, $value, true, $firing ? $since : $now, $notifiedAt);
        }

        if ($firing) {
            event(new AlertResolved($rule, $value));
            $this->notifier->send($rule, $value, resolved: true);
        }

        $this->store($rule, false, null, null);

        return new AlertStatus($rule, $value, false, null, null);
    }

    /**
     * The rule's stored state.
     *
     * @return array{firing: bool, since: ?CarbonInterface, notified_at: ?CarbonInterface}
     */
    protected function state(AlertRule $rule): array
    {
        $state = Cache::get($rule->stateKey());

        return [
            'firing' => (bool) ($state['firing'] ?? false),
            'since' => isset($state['since']) ? Date::parse($state['since']) : null,
            'notified_at' => isset($state['notified_at']) ? Date::parse($state['notified_at']) : null,
        ];
    }

    /**
     * Remember whether the rule is firing, since when, and when it last notified.
     */
    protected function store(AlertRule $rule, bool $firing, ?CarbonInterface $since, ?CarbonInterface $notifiedAt): void
    {
        Cache::forever($rule->stateKey(), [
            'firing' => $firing,
            'since' => $since?->toIso8601String(),
            'notified_at' => $notifiedAt?->toIso8601String(),
        ]);
    }
}
