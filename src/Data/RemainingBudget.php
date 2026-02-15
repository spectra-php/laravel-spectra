<?php

namespace Spectra\Data;

readonly class RemainingBudget extends DataTransferObject
{
    public function __construct(
        public ?int $daily = null,
        public ?int $weekly = null,
        public ?int $monthly = null,
        public ?int $total = null,
    ) {}

}
