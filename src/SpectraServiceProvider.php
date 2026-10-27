<?php

declare(strict_types=1);

namespace Spectra;

use Composer\InstalledVersions;
use Exception;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Spectra\Contracts\RequestExporter;
use Spectra\Contracts\SpanBuilder;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Integrations\OpenTelemetry\OtlpExporter;
use Spectra\Integrations\OpenTelemetry\SpanExporter;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\Budget\BudgetEnforcer;
use Spectra\Support\HttpMacros;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\RequestTransformer;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;
use Spectra\Support\Tracking\TraceContext;

class SpectraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/spectra.php', 'spectra'
        );

        // Scoped, so global tags, trackables, pricing tier and metadata are
        // reset between queued jobs and Octane requests instead of leaking.
        $this->app->scoped(Spectra::class, function ($app) {
            return new Spectra($app);
        });

        $this->app->alias(Spectra::class, 'spectra');

        $this->app->singleton(CostCalculator::class, function () {
            return new CostCalculator;
        });

        $this->app->singleton(BudgetEnforcer::class, function ($app) {
            return new BudgetEnforcer($app['events']);
        });

        $this->app->singleton(StatsAggregator::class, function () {
            return new StatsAggregator;
        });

        $this->app->singleton(RequestTransformer::class, function () {
            $class = config('spectra.integrations.request_transformer', RequestTransformer::class);

            if ($class !== RequestTransformer::class && class_exists($class)) {
                return $this->app->make($class);
            }

            return new RequestTransformer;
        });

        $this->app->singleton(RequestWriter::class, function ($app) {
            return new RequestWriter(
                $app->make(StatsAggregator::class),
                $app->make(RequestTransformer::class),
                $app->make(RequestExporter::class),
            );
        });

        $this->app->singleton(RequestPersister::class, function ($app) {
            return new RequestPersister(
                $app->make(RequestCostCalculator::class),
                $app->make(RequestWriter::class),
                $app->make(RequestExporter::class),
                $app->make(RequestTransformer::class)
            );
        });

        $this->app->singleton(PricingLookup::class, function () {
            $classes = config('spectra.costs.pricing', []);

            $pricingClasses = array_map(
                fn (string $class) => $this->app->make($class),
                array_values($classes)
            );

            return new PricingLookup($pricingClasses);
        });

        $this->app->alias(PricingLookup::class, 'spectra.pricing');

        $this->app->singleton(SpanBuilder::class, function () {
            $custom = config('spectra.integrations.opentelemetry.span_builder');

            if ($custom && class_exists($custom)) {
                return $this->app->make($custom);
            }

            return new DefaultSpanBuilder;
        });

        $this->app->singleton(SpanExporter::class, function ($app) {
            return new SpanExporter(
                config('app.name'),
                config('spectra.integrations.opentelemetry.service_version', '1.0.0'),
                config('spectra.integrations.opentelemetry.resource_attributes', []),
                $app->make(SpanBuilder::class)
            );
        });

        $this->app->singleton(OtlpExporter::class, function ($app) {
            return new OtlpExporter(
                config('spectra.integrations.opentelemetry.endpoint'),
                config('spectra.integrations.opentelemetry.headers', []),
                $app->make(SpanExporter::class)
            );
        });

        $this->app->singleton(RequestExporter::class, function ($app) {
            return $app->make(OtlpExporter::class);
        });

        $this->app->singleton(ApiKeyResolver::class, function () {
            return new ApiKeyResolver;
        });

        $this->registerProviders();
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerPublishing();
        $this->registerMiddleware();

        if (! $this->app->make('config')->get('spectra.enabled')) {
            return;
        }

        $this->registerAuthorization();
        $this->registerRoutes();
        $this->registerResources();
        $this->registerHttpMacros();
        $this->registerWatchers();
        $this->registerTracePropagation();
    }

    protected function registerAuthorization(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate, Application $app) {
            if (! $gate->has('viewSpectra')) {
                $gate->define('viewSpectra', fn ($user = null) => $app->environment('local'));
            }
        });
    }

    protected function registerWatchers(): void
    {
        if (! config('spectra.watcher.enabled')) {
            return;
        }

        $watchers = config('spectra.watcher.watchers');

        foreach ($watchers as $watcherClass) {
            if (! is_string($watcherClass)) {
                throw new Exception('Watcher configuration must be a class string.');
            }

            if (! class_exists($watcherClass)) {
                throw new Exception('Watcher class "'.$watcherClass.'" does not exist.');
            }

            if (! $watcherClass::isAvailable()) {
                continue;
            }

            $watcher = $this->app->make($watcherClass);
            $watcher->register();
            $this->app->instance($watcherClass, $watcher);
        }
    }

    /**
     * Give every queued job the trace of the request that dispatched it, even
     * when the job is dispatched before the request's first AI call, and the
     * tags its AI calls would get right now (global and withTags() ones too).
     */
    protected function registerTracePropagation(): void
    {
        Context::dehydrating(function (ContextRepository $context): void {
            if (! config('spectra.tracking.propagate_tags', true)) {
                $context->forgetHidden(TraceContext::TAGS);
            } elseif (($tags = $this->app->make(Spectra::class)->currentTags()) !== []) {
                $context->addHidden(TraceContext::TAGS, $tags);
            }

            if (! config('spectra.tracking.trace_per_request', true)) {
                return;
            }

            $context->addHidden(TraceContext::TRACE_ID, TraceContext::traceId());

            if (($parentSpanId = TraceContext::parentSpanId()) !== null) {
                $context->addHidden(TraceContext::PARENT_SPAN_ID, $parentSpanId);
            }
        });
    }

    protected function registerProviders(): void
    {
        $this->app->singleton(ProviderRegistry::class, function ($app) {
            return new ProviderRegistry(
                config('spectra.providers'),
                $app->make(ApiKeyResolver::class)
            );
        });

        $this->app->booted(function () {
            $providers = config('spectra.providers');

            foreach ($providers as $provider => $providerConfig) {
                $providerClass = is_array($providerConfig) ? ($providerConfig['class'] ?? null) : $providerConfig;

                if (is_string($providerClass) && class_exists($providerClass)) {
                    $this->app->singleton("spectra.provider.{$provider}", function () use ($providerClass) {
                        return new $providerClass;
                    });
                }
            }
        });
    }

    protected function registerRoutes(): void
    {
        $this->callAfterResolving('router', function (Router $router, Application $app) {
            if (! $app->make('config')->get('spectra.dashboard.enabled')) {
                return;
            }

            $router->middlewareGroup('spectra', $app->make('config')->get('spectra.dashboard.middleware', ['web']));

            $router->group([
                'domain' => $app->make('config')->get('spectra.dashboard.domain'),
                'prefix' => $app->make('config')->get('spectra.dashboard.path'),
                'middleware' => 'spectra',
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        });
    }

    protected function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spectra');
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spectra.php' => config_path('spectra.php'),
            ], ['spectra', 'spectra-config']);

            $this->publishes([
                __DIR__.'/../resources/views/layout.blade.php' => resource_path('views/vendor/spectra/layout.blade.php'),
            ], ['spectra', 'spectra-views']);

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], ['spectra', 'spectra-migrations']);
        }
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallCommand::class,
                Console\Commands\PruneCommand::class,
                Console\Commands\RebuildStatsCommand::class,
            ]);

            AboutCommand::add('Spectra', fn () => [
                'Version' => $this->getVersion(),
                'Enabled' => AboutCommand::format(
                    config('spectra.enabled'),
                    console: fn ($value) => $value ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF'
                ),
            ]);
        }
    }

    protected function getVersion(): ?string
    {
        return InstalledVersions::getPrettyVersion('spectra-php/laravel-spectra');
    }

    protected function registerMiddleware(): void
    {
        $this->callAfterResolving('router', function (Router $router) {
            $router->aliasMiddleware('spectra.budget', Http\Middleware\EnforceBudgetLimit::class);
            $router->aliasMiddleware('spectra.trace', Http\Middleware\ContinueTrace::class);
        });
    }

    protected function registerHttpMacros(): void
    {
        HttpMacros::register();
    }
}
