<?php

namespace Spectra\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spectra\Contracts\RequestExporter;
use Spectra\Events\RequestTracked;
use Spectra\Models\SpectraRequest;
use Spectra\Support\RequestTransformer;
use Spectra\Support\StatsAggregator;

class PersistSpectraRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $tags,
    ) {}

    public function handle(StatsAggregator $statsAggregator, RequestTransformer $transformer, RequestExporter $exporter): void
    {
        if ($this->attributes['response_id'] ?? null) {
            $request = SpectraRequest::updateOrCreate(
                ['response_id' => $this->attributes['response_id']],
                $this->attributes
            );
        } else {
            $request = SpectraRequest::create($this->attributes);
        }

        if (! empty($this->tags)) {
            $request->attachTags($this->tags);
        }

        $statsAggregator->recordRequest($request);

        $data = $transformer->transform($request);

        RequestTracked::dispatch($data);

        if (config('spectra.integrations.opentelemetry.enabled')) {
            $exporter->export($data);
        }
    }
}
