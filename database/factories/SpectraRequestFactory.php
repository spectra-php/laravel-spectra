<?php

namespace Spectra\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Spectra\Models\SpectraRequest;

/**
 * @extends Factory<SpectraRequest>
 */
class SpectraRequestFactory extends Factory
{
    protected $model = SpectraRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => config('spectra.budget.default_provider', 'openai'),
            'model' => config('spectra.budget.default_model', 'gpt-4'),
            'response' => json_encode(['prompt' => 'test']),
            'total_cost_in_cents' => 0,
            'status_code' => 200,
            'created_at' => now(),
        ];
    }

    public function forTrackable(Model $trackable): static
    {
        return $this->state([
            'trackable_type' => $trackable->getMorphClass(),
            'trackable_id' => $trackable->getKey(),
        ]);
    }

    public function withCost(float $cents): static
    {
        return $this->state(['total_cost_in_cents' => $cents]);
    }

    public function withTokens(int $prompt, int $completion): static
    {
        return $this->state([
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
        ]);
    }
}
