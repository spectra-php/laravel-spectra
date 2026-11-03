<?php

declare(strict_types=1);

namespace Spectra;

use Composer\InstalledVersions;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spectra\Contracts\RequestExporter;
use Spectra\Contracts\SpanBuilder;
use Spectra\Contracts\Watcher;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Integrations\OpenTelemetry\OtlpExporter;
use Spectra\Integrations\OpenTelemetry\SpanExporter;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\Budget\BudgetEnforcer;
use Spectra\Support\HttpMacros;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\RequestTransformer;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;
use Spectra\Support\Tracking\TraceContext;
use Spectra\Support\Tracking\TraceOrigin;

/**
 * Wires Spectra into the host application: config, container bindings,
 * watchers on outgoing AI calls, the dashboard routes, commands and
 * publishing.
 *
 * Spectra runs inside other people's apps, so a bad config entry is
 * reported and skipped instead of failing the app's boot.
 */
class SpectraServiceProvider extends ServiceProvider
{
    /** @var array<string, mixed>|null */
    protected ?array $packageConfig = null;

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/spectra.php', 'spectra'
        );

        $this->mergeNestedConfigDefaults();
        $this->mergeBuiltInMaps();

        // Scoped, so global tags, trackables, pricing tier and metadata are
        // reset between queued jobs and Octane requests instead of leaking.
        // Scoped: queue workers and Octane reset it before each job and request.
        $this->app->scoped(TraceOrigin::class);

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
            return new PricingLookup(PricingCatalog::active());
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
        $this->registerOriginTracking();
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

        $watchers = config('spectra.watcher.watchers', []);

        foreach (is_array($watchers) ? $watchers : [] as $watcherClass) {
            // is_subclass_of() throws on a non-string entry under strict types.
            if (! is_string($watcherClass) || ! is_subclass_of($watcherClass, Watcher::class)) {
                $this->reportInvalidWatcher($watcherClass);

                continue;
            }

            if (! $watcherClass::isAvailable()) {
                continue;
            }

            $watcher = $this->app->make($watcherClass);
            $watcher->register();
            $this->app->instance($watcherClass, $watcher);
        }
    }

    protected function reportInvalidWatcher(mixed $watcherClass): void
    {
        $name = is_string($watcherClass) ? $watcherClass : get_debug_type($watcherClass);

        report(new InvalidArgumentException(
            "Spectra skipped the watcher [{$name}] in spectra.watcher.watchers: it is not a class implementing Spectra\\Contracts\\Watcher."
        ));
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

    /**
     * Tell TraceOrigin which job or command is running, so the AI calls they
     * make are stored with its name.
     */
    protected function registerOriginTracking(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $origin = fn (): TraceOrigin => $this->app->make(TraceOrigin::class);

        // A failure here must never stop the user's job or command.
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => rescue(fn () => $origin()->startJob($event->job, $event->job->resolveName())));
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => rescue(fn () => $origin()->finishJob($event->job)));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => rescue(fn () => $origin()->finishJob($event->job)));

        $events->listen(CommandStarting::class, fn (CommandStarting $event) => rescue(fn () => $origin()->startCommand($event->command)));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => rescue(fn () => $origin()->finishCommand($event->command)));
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
            // An entry set to false turns that built-in provider off.
            $providers = collect((array) config('spectra.providers', []))->filter();

            foreach ($providers as $provider => $providerConfig) {
                $providerClass = is_array($providerConfig) ? ($providerConfig['class'] ?? null) : $providerConfig;

                if (filled($providerClass) && class_exists($providerClass)) {
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

    /**
     * Keys added to existing config sections after release.
     *
     * mergeConfigFrom() only merges top-level keys, so a config published
     * before these keys existed would not have them, and their env variables
     * would silently do nothing.
     */
    protected const NESTED_CONFIG_DEFAULTS = [
        'tracking.capture_origin',
        'costs.use_scraped_prices',
        'costs.scraped_prices_path',
        'costs.firecrawl',
    ];

    /**
     * Maps whose built-in entries stay active under the user's published
     * config: a published config lists the providers and pricing classes of
     * its day, and would otherwise hide every one added later. The user's
     * entries win; `false` turns a built-in off.
     */
    protected const BUILT_IN_MAPS = [
        'providers',
        'costs.pricing',
    ];

    protected function mergeNestedConfigDefaults(): void
    {
        if ($this->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $missing = array_filter(self::NESTED_CONFIG_DEFAULTS, fn (string $key) => ! $config->has("spectra.{$key}"));

        if ($missing === []) {
            return;
        }

        $defaults = $this->packageConfig();

        foreach ($missing as $key) {
            $config->set("spectra.{$key}", data_get($defaults, $key));
        }
    }

    protected function mergeBuiltInMaps(): void
    {
        if ($this->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $defaults = $this->packageConfig();

        foreach (self::BUILT_IN_MAPS as $key) {
            $configured = $config->get("spectra.{$key}");

            $config->set("spectra.{$key}", array_replace(
                (array) data_get($defaults, $key, []),
                is_array($configured) ? $configured : [],
            ));
        }
    }

    /**
     * The package's own config file, read once per boot.
     *
     * @return array<string, mixed>
     */
    protected function packageConfig(): array
    {
        return $this->packageConfig ??= require __DIR__.'/../config/spectra.php';
    }

    /**
     * A cached config was merged when it was cached, so it is used as is.
     */
    protected function configurationIsCached(): bool
    {
        return $this->app instanceof CachesConfiguration && $this->app->configurationIsCached();
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallCommand::class,
                Console\Commands\PruneCommand::class,
                Console\Commands\RebuildStatsCommand::class,
                Console\Commands\ScrapePricingCommand::class,
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
