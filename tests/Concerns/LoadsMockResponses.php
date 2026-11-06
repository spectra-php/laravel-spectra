<?php

declare(strict_types=1);

namespace Spectra\Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait LoadsMockResponses
{
    /**
     * Load a mock response fixture from tests/responses/.
     *
     * @param  string  $path  Relative path (e.g., 'openai/completion.json')
     * @return array<int|string, mixed>
     */
    protected function loadMockResponse(string $path): array
    {
        $fullPath = __DIR__.'/../responses/'.$path;

        if (Str::endsWith($path, '.jsonl')) {
            return File::lines($fullPath)
                ->reject(fn (string $line) => $line === '')
                ->map(fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR))
                ->values()
                ->all();
        }

        return json_decode(File::get($fullPath), true, 512, JSON_THROW_ON_ERROR);
    }
}
