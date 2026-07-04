<?php

declare(strict_types=1);

namespace Spectra\Support\Macros;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Spectra\Concerns\ParsesRequestData;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Spectra;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\Tracking\StreamDetector;

class WithTrackingMacro
{
    use ParsesRequestData;

    public static function register(): void
    {
        Http::macro('withAITracking', function (string $provider, string $model = 'unknown', array $options = []) {
            /** @var PendingRequest $this */
            $manager = app(Spectra::class);
            $context = null;
            $endpoint = '';

            return $this
                ->withHeaders(['X-Spectra-Manual-Tracking' => '1'])
                ->beforeSending(function ($request) use ($manager, $provider, &$model, $options, &$context, &$endpoint) {
                    if (! $manager->isEnabled()) {
                        return;
                    }

                    $requestData = self::parseRequestData($request);

                    if (isset($requestData['model'])) {
                        $model = $requestData['model'];
                    }

                    $psr = $request->toPsrRequest();
                    $endpoint = $psr->getUri()->getPath();

                    $context = $manager->startRequest($provider, $model, array_merge([
                        'endpoint' => $endpoint,
                        'operation' => $request->method(),
                    ], $options));

                    $context->endpoint = $endpoint;
                    $context->requestData = $requestData;

                    if (isset($options['pricing_tier'])) {
                        $context->withPricingTier($options['pricing_tier']);
                    }
                })
                ->withResponseMiddleware(function (ResponseInterface $response) use ($manager, $provider, &$context, &$endpoint) {
                    if (! $context || ! $manager->isEnabled()) {
                        return $response;
                    }

                    $statusCode = $response->getStatusCode();

                    if ($statusCode >= 400) {
                        $manager->recordFailure(
                            $context,
                            new \Exception((string) $response->getBody()),
                            $statusCode
                        );

                        return $response;
                    }

                    if (StreamDetector::isStreamingResponse($response, $provider, $endpoint, $context->requestData ?? [])) {
                        $manager->setPendingStreamContext($context);

                        return $response;
                    }

                    $providerInstance = app(ProviderRegistry::class)->provider($provider);
                    $rawBody = (string) $response->getBody();
                    $handler = $providerInstance?->resolveHandler($context->endpoint ?? '');

                    if ($handler instanceof ReturnsBinaryResponse) {
                        $context->rawResponseBody = $rawBody;
                        $body = [];
                    } else {
                        $body = json_decode($rawBody, true) ?? [];
                    }

                    if ($handler instanceof SkipsResponse && $handler->shouldSkipResponse($body)) {
                        return $response;
                    }

                    $manager->recordSuccess($context, $body);

                    return $response;
                });
        });
    }
}
