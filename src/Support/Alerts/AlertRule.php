<?php

declare(strict_types=1);

namespace Spectra\Support\Alerts;

use InvalidArgumentException;
use Spectra\Enums\AlertMetric;

/**
 * One rule from `spectra.alerts.rules`: a metric, a threshold it must stay
 * above or below, the window it is measured over, and optionally the provider
 * and model it watches.
 */
readonly class AlertRule
{
    /**
     * Create a new alert rule instance.
     */
    public function __construct(
        public string $name,
        public AlertMetric $metric,
        public float $threshold,
        public bool $above,
        public int $windowMinutes = 15,
        public int $minRequests = 10,
        public ?string $provider = null,
        public ?string $model = null,
    ) {}

    /**
     * Build a rule from its config entry, failing loudly on a typo so a
     * misconfigured alert is found when it is checked, not when it was needed.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $name = $config['name'] ?? null;
        $metric = AlertMetric::tryFrom((string) ($config['metric'] ?? ''));

        throw_if(blank($name) || ! is_string($name), InvalidArgumentException::class, 'Every Spectra alert rule needs a name.');
        throw_if($metric === null, InvalidArgumentException::class, "The Spectra alert [{$name}] has an unknown metric. Use one of: ".collect(AlertMetric::cases())->pluck('value')->implode(', ').'.');
        throw_if(isset($config['above']) === isset($config['below']), InvalidArgumentException::class, "The Spectra alert [{$name}] needs either `above` or `below`.");

        $threshold = $config['above'] ?? $config['below'];
        throw_unless(is_numeric($threshold), InvalidArgumentException::class, "The Spectra alert [{$name}] needs a numeric threshold.");

        return new self(
            name: $name,
            metric: $metric,
            threshold: (float) $threshold,
            above: isset($config['above']),
            windowMinutes: max(1, (int) ($config['window'] ?? 15)),
            minRequests: max(1, (int) ($config['min_requests'] ?? 10)),
            provider: isset($config['provider']) ? (string) $config['provider'] : null,
            model: isset($config['model']) ? (string) $config['model'] : null,
        );
    }

    /**
     * Determine if the value breaks the rule.
     */
    public function isBreachedBy(float $value): bool
    {
        return $this->above ? $value > $this->threshold : $value < $this->threshold;
    }

    /**
     * Get the rule as a sentence: "Error rate above 10% over 15 minutes (openai)".
     */
    public function describe(): string
    {
        $scope = collect([$this->provider, $this->model])->filter()->implode(' / ');

        return $this->metric->label().' '.($this->above ? 'above' : 'below').' '.$this->metric->format($this->threshold)
            .' over '.$this->window().($scope !== '' ? " ({$scope})" : '');
    }

    /**
     * Get the window as a person says it: "15 minutes", "1 hour", "24 hours".
     */
    public function window(): string
    {
        if ($this->windowMinutes % 60 === 0) {
            $hours = intdiv($this->windowMinutes, 60);

            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        return $this->windowMinutes === 1 ? '1 minute' : "{$this->windowMinutes} minutes";
    }

    /**
     * Get the cache key that holds this rule's state.
     */
    public function stateKey(): string
    {
        return 'spectra:alerts:'.sha1($this->name);
    }
}
