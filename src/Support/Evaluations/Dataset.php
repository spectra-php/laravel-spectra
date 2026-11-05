<?php

declare(strict_types=1);

namespace Spectra\Support\Evaluations;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraDataset;
use Spectra\Models\SpectraDatasetExample;
use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;

/**
 * A dataset by name, made the first time something is added to it:
 *
 *   Spectra::dataset('refund-questions')->add(['question' => 'Can I get my money back?'], expected: 'Yes, within 30 days.');
 */
class Dataset
{
    /**
     * Create a new dataset handle instance.
     */
    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Add an example: the input the evaluation passes to the code under
     * test, and the output expected for it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function add(mixed $input, mixed $expected = null, array $metadata = [], ?string $sourceRequestId = null): SpectraDatasetExample
    {
        return $this->model()->examples()->create([
            'input' => $input,
            'expected' => $expected,
            'metadata' => $metadata === [] ? null : $metadata,
            'source_request_id' => $sourceRequestId,
        ]);
    }

    /**
     * Add a tracked call as an example: its last user message as the input
     * (`['prompt' => ...]`), and its reply as the expected output when asked.
     */
    public function addRequest(SpectraRequest|string $request, bool $replyIsExpected = false): SpectraDatasetExample
    {
        $request = $request instanceof SpectraRequest ? $request : SpectraRequest::query()->findOrFail($request);
        $request->makeVisible(['request', 'response']);
        $messages = app(MessageTextExtractor::class);

        return $this->add(
            input: ['prompt' => $messages->prompt($request->request)],
            expected: $replyIsExpected ? $messages->reply($request) : null,
            metadata: ['model' => $request->snapshot ?? $request->model, 'provider' => $request->provider],
            sourceRequestId: $request->id,
        );
    }

    /**
     * The dataset's examples, oldest first.
     *
     * @return Collection<int, SpectraDatasetExample>
     */
    public function examples(): Collection
    {
        return $this->model()->examples()->oldest('created_at')->get();
    }

    /**
     * The dataset row, made when missing.
     */
    public function model(): SpectraDataset
    {
        return SpectraDataset::query()->firstOrCreate(['name' => $this->name]);
    }
}
