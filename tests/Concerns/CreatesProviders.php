<?php

namespace Spectra\Tests\Concerns;

use Spectra\Providers\Cohere\Cohere;
use Spectra\Providers\FalAi\FalAi;
use Spectra\Providers\Google\Google;
use Spectra\Providers\Google\Handlers\VideoHandler as GoogleVideoHandler;
use Spectra\Providers\Groq\Groq;
use Spectra\Providers\Mistral\Mistral;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Providers\Replicate\Replicate;
use Spectra\Providers\XAi\XAi;

trait CreatesProviders
{
    protected function openAiProvider(): OpenAI
    {
        return new OpenAI;
    }

    protected function falAiProvider(): FalAi
    {
        return new FalAi;
    }

    protected function googleProvider(): Google
    {
        return new Google;
    }

    protected function replicateProvider(): Replicate
    {
        return new Replicate;
    }

    protected function mistralProvider(): Mistral
    {
        return new Mistral;
    }

    protected function xAiProvider(): XAi
    {
        return new XAi;
    }

    protected function groqProvider(): Groq
    {
        return new Groq;
    }

    protected function cohereProvider(): Cohere
    {
        return new Cohere;
    }

    protected function googleVideoHandler(): GoogleVideoHandler
    {
        return new GoogleVideoHandler;
    }
}
