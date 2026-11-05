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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spectra\Console\Commands\CheckAlertsCommand;
use Spectra\Console\Commands\EvalCommand;
use Spectra\Console\Commands\GroupErrorsCommand;
use Spectra\Console\Commands\InstallCommand;
use Spectra\Console\Commands\PauseCommand;
use Spectra\Console\Commands\PruneCommand;
use Spectra\Console\Commands\RebuildStatsCommand;
use Spectra\Console\Commands\ResumeCommand;
use Spectra\Console\Commands\ScrapePricingCommand;
use Spectra\Contracts\RequestExporter;
use Spectra\Contracts\SpanBuilder;
use Spectra\Contracts\Watcher;
use Spectra\Enums\SpanKind;
use Spectra\Http\Middleware\ContinueTrace;
use Spectra\Http\Middleware\EnforceBudgetLimit;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Integrations\OpenTelemetry\OtlpExporter;
use Spectra\Integrations\OpenTelemetry\SpanExporter;
use Spectra\Support\Annotations\AnnotationQueues;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\Budget\BudgetEnforcer;
use Spectra\Support\HttpMacros;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\Redaction\Redactor;
use Spectra\Support\RequestTransformer;
use Spectra\Support\Scoring\ScoreRecorder;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RecordingSwitch;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;
use Spectra\Support\Tracking\SpanStack;
use Spectra\Support\Tracking\SpanWriter;
use Spectra\Support\Tracking\ToolSpanRecorder;
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

    /**
     * Register Spectra's config and container bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/spectra.php', 'spectra'
        );

        $this->mergeNestedConfigDefaults();
        $this->mergeBuiltInMaps();

        // Scoped, so global tags, trackables, pricing tier and metadata are
        // reset between queued jobs and Octane requests instead of leaking.
        $this->app->scoped(Spectra::class, fn ($app) => new Spectra($app));

        // Scoped too: queue workers and Octane reset them before each job and request.
        $this->app->scoped(TraceOrigin::class);
        $this->app->scoped(SpanStack::class);
        $this->app->scoped(ToolSpanRecorder::class);
        $this->app->scoped(RecordingSwitch::class);
        $this->app->singleton(SpanWriter::class);
        $this->app->singleton(ScoreRecorder::class);
        $this->app->singleton(AnnotationQueues::class);
        $this->app->singleton(Redactor::class);

        $this->app->alias(Spectra::class, 'spectra');

        $this->app->singleton(CostCalculator::class, fn () => new CostCalculator);

        $this->app->singleton(BudgetEnforcer::class, fn ($app) => new BudgetEnforcer($app['events']));

        $this->app->singleton(StatsAggregator::class, fn () => new StatsAggregator);

        $this->app->singleton(RequestTransformer::class, function () {
            $class = config('spectra.integrations.request_transformer', RequestTransformer::class);

            if ($class !== RequestTransformer::class && class_exists($class)) {
                return $this->app->make($class);
            }

            return new RequestTransformer;
        });

        $this->app->singleton(RequestWriter::class, fn ($app) => new RequestWriter(
            $app->make(StatsAggregator::class),
            $app->make(RequestTransformer::class),
            $app->make(RequestExporter::class),
        ));

        $this->app->singleton(RequestPersister::class, fn ($app) => new RequestPersister(
            $app->make(RequestCostCalculator::class),
            $app->make(RequestWriter::class),
            $app->make(RequestExporter::class),
            $app->make(RequestTransformer::class)
        ));

        $this->app->singleton(PricingLookup::class, fn () => new PricingLookup(PricingCatalog::active()));

        $this->app->alias(PricingLookup::class, 'spectra.pricing');

        $this->app->singleton(SpanBuilder::class, function () {
            $custom = config('spectra.integrations.opentelemetry.span_builder');

            if ($custom && class_exists($custom)) {
                return $this->app->make($custom);
            }

            return new DefaultSpanBuilder;
        });

        $this->app->singleton(SpanExporter::class, fn ($app) => new SpanExporter(
            config('app.name'),
            config('spectra.integrations.opentelemetry.service_version', '1.0.0'),
            config('spectra.integrations.opentelemetry.resource_attributes', []),
            $app->make(SpanBuilder::class)
        ));

        $this->app->singleton(OtlpExporter::class, fn ($app) => new OtlpExporter(
            config('spectra.integrations.opentelemetry.endpoint'),
            config('spectra.integrations.opentelemetry.headers', []),
            $app->make(SpanExporter::class)
        ));

        $this->app->singleton(RequestExporter::class, fn ($app) => $app->make(OtlpExporter::class));

        $this->app->singleton(ApiKeyResolver::class, fn () => new ApiKeyResolver);

        $this->registerProviders();
    }

    /**
     * Boot the commands, publishing and middleware, then the tracking when it is enabled.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerPublishing();
        $this->registerMiddleware();
        // The macros are public API that call sites keep using with Spectra
        // off: they must exist, and then record nothing.
        $this->registerHttpMacros();

        if (! $this->app->make('config')->get('spectra.enabled')) {
            return;
        }

        $this->registerAuthorization();
        $this->registerRoutes();
        $this->registerResources();
        $this->registerWatchers();
        $this->registerTracePropagation();
        $this->registerOriginTracking();
    }

    /**
     * Define the viewSpectra and manageSpectra gates as local-only, unless the
     * app defined its own. manageSpectra runs the dashboard's maintenance
     * actions, so allowing viewSpectra in production does not allow it.
     * annotateSpectra (reviewing traces in the annotation queues) follows
     * viewSpectra unless the app defines it.
     */
    protected function registerAuthorization(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate, Application $app) {
            foreach (['viewSpectra', 'manageSpectra'] as $ability) {
                if (! $gate->has($ability)) {
                    $gate->define($ability, fn ($user = null) => $app->environment('local'));
                }
            }

            if (! $gate->has('annotateSpectra')) {
                $gate->define('annotateSpectra', fn ($user = null) => $gate->forUser($user)->allows('viewSpectra'));
            }
        });
    }

    /**
     * Register the configured watchers that are available, skipping invalid entries.
     */
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

    /**
     * Report a watcher entry that is not a class implementing the Watcher contract.
     */
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

            // The job runs under the span open right now (a Spectra::span()
            // block, or this request's own span), else under `traceparent`.
            $parentSpanId = rescue(
                fn () => $this->app->make(SpanStack::class)->parentForDispatch(TraceContext::traceId(), TraceContext::parentSpanId()),
                fn () => TraceContext::parentSpanId(),
            );

            if ($parentSpanId !== null) {
                $context->addHidden(TraceContext::PARENT_SPAN_ID, $parentSpanId);
            }

            // That span, and the ones before it, when nothing saves them: the
            // job saves them with its own, so it never hangs off a missing span.
            $pending = rescue(fn () => $this->app->make(SpanStack::class)->pendingForDispatch(TraceContext::traceId()), []);

            if ($pending === []) {
                $context->forgetHidden(TraceContext::PENDING_SPANS);
            } else {
                $context->addHidden(TraceContext::PENDING_SPANS, $pending);
            }
        });
    }

    /**
     * Tell TraceOrigin and SpanStack which job or command is running, so the
     * AI calls they make are stored with its name and nest under its span,
     * and save the span of the HTTP request once the app is done with it.
     */
    protected function registerOriginTracking(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $origin = fn (): TraceOrigin => $this->app->make(TraceOrigin::class);
        $spans = fn (): SpanStack => $this->app->make(SpanStack::class);
        $jobKey = fn (object $job): string => 'job:'.spl_object_id($job);

        // A failure here must never stop the user's job or command.
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => rescue(function () use ($event, $origin, $spans, $jobKey) {
            $name = $event->job->resolveName();

            $origin()->startJob($event->job, $name);
            $spans()->startUnit($jobKey($event->job), SpanKind::Job, config('spectra.tracking.capture_origin', true) ? $name : 'Queued job');
        }));
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => rescue(function () use ($event, $origin, $spans, $jobKey) {
            $origin()->finishJob($event->job);
            $spans()->finishUnit($jobKey($event->job));
        }));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => rescue(function () use ($event, $origin, $spans, $jobKey) {
            $origin()->finishJob($event->job);
            $spans()->finishUnit($jobKey($event->job), $event->exception);
        }));

        $events->listen(CommandStarting::class, fn (CommandStarting $event) => rescue(function () use ($event, $origin, $spans) {
            $origin()->startCommand($event->command);
            $spans()->startCommand($event->command);
        }));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => rescue(function () use ($event, $origin, $spans) {
            $origin()->finishCommand($event->command);
            $spans()->finishCommand($event->command);
        }));

        $this->app->terminating(fn () => rescue(fn () => $spans()->finishRequest()));
    }

    /**
     * Register the provider registry and a singleton for each enabled provider class.
     */
    protected function registerProviders(): void
    {
        $this->app->singleton(ProviderRegistry::class, fn ($app) => new ProviderRegistry(
            config('spectra.providers'),
            $app->make(ApiKeyResolver::class)
        ));

        $this->app->booted(function () {
            // An entry set to false turns that built-in provider off.
            $providers = collect((array) config('spectra.providers', []))->filter();

            foreach ($providers as $provider => $providerConfig) {
                $providerClass = is_array($providerConfig) ? ($providerConfig['class'] ?? null) : $providerConfig;

                if (filled($providerClass) && class_exists($providerClass)) {
                    $this->app->singleton("spectra.provider.{$provider}", fn () => new $providerClass);
                }
            }
        });
    }

    /**
     * Register the dashboard routes under the configured path, domain and middleware.
     */
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

    /**
     * Register the dashboard views.
     */
    protected function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spectra');
    }

    /**
     * Register the config, views and migrations users can publish.
     */
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
        // 2.0
        'storage.store_request_data',
        'tracking.trace_per_request',
        'tracking.use_traceparent',
        'costs.exchange_rates',
        // 2.x
        'tracking.propagate_tags',
        'costs.use_scraped_prices',
        'costs.scraped_prices_path',
        'costs.firecrawl',
        // 3.0
        'tracking.capture_origin',
        'tracking.root_spans',
        // 4.0
        'storage.sampling',
        'storage.redaction',
        'tracking.tool_spans',
        'dashboard.maintenance',
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

    /**
     * Fill in nested config keys missing from an older published config.
     */
    protected function mergeNestedConfigDefaults(): void
    {
        if ($this->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $missing = Arr::where(self::NESTED_CONFIG_DEFAULTS, fn (string $key) => ! $config->has("spectra.{$key}"));

        if ($missing === []) {
            return;
        }

        $defaults = $this->packageConfig();

        foreach ($missing as $key) {
            $config->set("spectra.{$key}", data_get($defaults, $key));
        }
    }

    /**
     * Keep the built-in providers and pricing classes under the user's published entries.
     */
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
     * Get the package's own config file, read once per boot.
     *
     * @return array<string, mixed>
     */
    protected function packageConfig(): array
    {
        return $this->packageConfig ??= require __DIR__.'/../config/spectra.php';
    }

    /**
     * Determine if the app's config is cached.
     *
     * A cached config was merged when it was cached, so it is used as is.
     */
    protected function configurationIsCached(): bool
    {
        return $this->app instanceof CachesConfiguration && $this->app->configurationIsCached();
    }

    /**
     * Register the artisan commands and Spectra's section of the about command.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckAlertsCommand::class,
                EvalCommand::class,
                GroupErrorsCommand::class,
                InstallCommand::class,
                PauseCommand::class,
                PruneCommand::class,
                RebuildStatsCommand::class,
                ResumeCommand::class,
                ScrapePricingCommand::class,
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

    /**
     * Get the installed version of the package.
     */
    protected function getVersion(): ?string
    {
        return InstalledVersions::getPrettyVersion('spectra-php/laravel-spectra');
    }

    /**
     * Register the spectra.budget and spectra.trace middleware aliases.
     */
    protected function registerMiddleware(): void
    {
        $this->callAfterResolving('router', function (Router $router) {
            $router->aliasMiddleware('spectra.budget', EnforceBudgetLimit::class);
            $router->aliasMiddleware('spectra.trace', ContinueTrace::class);
        });
    }

    /**
     * Register the Http::withAITracking() and withoutAITracking() macros.
     */
    protected function registerHttpMacros(): void
    {
        HttpMacros::register();
    }
}
