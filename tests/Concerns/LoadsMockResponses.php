<?php

namespace Spectra\Tests\Concerns;

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

        if (str_ends_with($path, '.jsonl')) {
            $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            return array_map(fn ($line) => json_decode($line, true), $lines);
        }

        return json_decode(file_get_contents($fullPath), true);
    }
}
