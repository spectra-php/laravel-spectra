<?php

declare(strict_types=1);

namespace Spectra\Support\Evaluations;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spectra\Models\SpectraDataset;
use Spectra\Models\SpectraDatasetExample;
use Spectra\Models\SpectraEvalRun;
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
        $textExtractor = app(MessageTextExtractor::class);

        return $this->add(
            input: ['prompt' => $textExtractor->prompt($request->request)],
            expected: $replyIsExpected ? $textExtractor->reply($request) : null,
            metadata: ['model' => $request->snapshot ?? $request->model, 'provider' => $request->provider],
            sourceRequestId: $request->id,
        );
    }

    /**
     * Get the dataset's examples, oldest first.
     *
     * @return Collection<int, SpectraDatasetExample>
     */
    public function examples(): Collection
    {
        return $this->model()->examples()->oldest('created_at')->get();
    }

    /**
     * Rename the dataset, and the runs over it, which store its name. Code that
     * names the dataset, such as an evaluation's dataset(), has to change too.
     *
     * @throws InvalidArgumentException when the name is empty, longer than 100 characters, or another dataset's
     */
    public function rename(string $name): self
    {
        $dataset = SpectraDataset::query()->where('name', $this->name)->firstOrFail();
        $name = Str::trim($name);

        throw_if(blank($name) || Str::length($name) > 100, InvalidArgumentException::class, 'A dataset needs a name of 1 to 100 characters.');
        throw_if(
            SpectraDataset::query()->where('name', $name)->whereKeyNot($dataset->id)->exists(),
            InvalidArgumentException::class,
            "A dataset named [{$name}] already exists.",
        );

        $dataset->getConnection()->transaction(function () use ($dataset, $name) {
            SpectraEvalRun::query()->where('dataset', $this->name)->update(['dataset' => $name]);
            $dataset->update(['name' => $name]);
        });

        return new self($name);
    }

    /**
     * Delete the dataset and its examples. Past runs stay, with the results
     * they saved; a new dataset with the same name shows them again.
     */
    public function delete(): void
    {
        $dataset = SpectraDataset::query()->where('name', $this->name)->first();

        if ($dataset === null) {
            return;
        }

        $dataset->getConnection()->transaction(function () use ($dataset) {
            $dataset->examples()->delete();
            $dataset->delete();
        });
    }

    /**
     * Get the dataset row, creating it when missing.
     */
    public function model(): SpectraDataset
    {
        return SpectraDataset::query()->firstOrCreate(['name' => $this->name]);
    }
}
