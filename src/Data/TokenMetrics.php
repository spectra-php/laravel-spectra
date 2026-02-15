<?php

namespace Spectra\Data;

readonly class TokenMetrics extends DataTransferObject
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cachedTokens = 0,
        public int $reasoningTokens = 0,
        public int $cacheCreationTokens = 0,
    ) {}

}
