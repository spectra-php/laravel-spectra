<?php

declare(strict_types=1);

namespace Spectra\Enums;

enum PricingUnit: string
{
    case Tokens = 'tokens';
    case Minute = 'minute';
    case Second = 'second';
    case Characters = 'characters';
    case Image = 'image';
    case Video = 'video';
    case Search = 'search';
}
