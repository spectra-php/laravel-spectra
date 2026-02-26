<?php

use Spectra\Tests\Concerns\CreatesProviders;
use Spectra\Tests\Concerns\GeneratesAudio;
use Spectra\Tests\Concerns\InteractsWithHttpWatcher;
use Spectra\Tests\Concerns\LoadsMockResponses;
use Spectra\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(LoadsMockResponses::class)->in('Feature');
uses(CreatesProviders::class)->in('Feature', 'Unit');
uses(GeneratesAudio::class)->in('Feature', 'Unit');
uses(InteractsWithHttpWatcher::class)->in('Unit');
