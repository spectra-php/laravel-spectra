<?php

declare(strict_types=1);

namespace Spectra\Support;

use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Spectra\Models\SpectraDashboard;

/**
 * Inlines the dashboard's built CSS, JavaScript and favicons into its Blade
 * layout, so the package works without publishing assets or a Vite build in
 * the host app, and passes the runtime config the SPA reads as
 * `window.Spectra`.
 */
class DashboardAssets
{
    /**
     * Process-wide on purpose: like Telescope's night(), it is set once while
     * the app boots (a service provider calls useDarkTheme()) and never per
     * request, so it is the same for every request a long-lived worker
     * serves. Kept public for apps that already call it.
     */
    public static bool $useDarkTheme = false;

    /**
     * Get the dashboard favicon link tags, with each icon inlined as a data URI.
     */
    public static function favicons(): HtmlString
    {
        $distPath = __DIR__.'/../../resources/images/favicons';
        $icons = [
            ['file' => 'favicon.svg', 'rel' => 'icon', 'type' => 'image/svg+xml', 'sizes' => null],
            ['file' => 'favicon.ico', 'rel' => 'icon', 'type' => 'image/x-icon', 'sizes' => null],
            ['file' => 'favicon-96x96.png', 'rel' => 'icon', 'type' => 'image/png', 'sizes' => '96x96'],
            ['file' => 'apple-touch-icon.png', 'rel' => 'apple-touch-icon', 'type' => 'image/png', 'sizes' => '180x180'],
        ];

        $tags = [];

        foreach ($icons as $icon) {
            $filePath = "{$distPath}/{$icon['file']}";

            if (! File::exists($filePath)) {
                continue;
            }

            $encodedContent = base64_encode(File::get($filePath));

            $href = "data:{$icon['type']};base64,{$encodedContent}";
            $sizes = filled($icon['sizes']) ? " sizes=\"{$icon['sizes']}\"" : '';
            $tags[] = "<link rel=\"{$icon['rel']}\" type=\"{$icon['type']}\"{$sizes} href=\"{$href}\">";
        }

        return new HtmlString(implode("\n", $tags));
    }

    /**
     * Get the compiled dashboard CSS as an inline style tag.
     */
    public static function css(): HtmlString
    {
        $cssPath = __DIR__.'/../../dist/app.css';

        if (File::exists($cssPath)) {
            $css = File::get($cssPath);

            return new HtmlString(<<<HTML
                <style>{$css}</style>
            HTML);
        }

        Log::warning("Spectra dashboard CSS not found at {$cssPath} — the dashboard will render unstyled. Run `npm run build` in the package to generate the dist assets.");

        return new HtmlString('');
    }

    /**
     * Get the compiled dashboard JavaScript as an inline module script, with its config.
     */
    public static function js(): HtmlString
    {
        $jsPath = __DIR__.'/../../dist/app.js';

        if (File::exists($jsPath)) {
            $js = File::get($jsPath);
            $config = Js::from(static::scriptVariables());

            return new HtmlString(<<<HTML
                <script type="module">
                    window.Spectra = {$config};
                    {$js}
                </script>
            HTML);
        }

        Log::warning("Spectra dashboard JavaScript not found at {$jsPath} — the dashboard will not load. Run `npm run build` in the package to generate the dist assets.");

        return new HtmlString('');
    }

    /**
     * Get a shared dashboard's compiled JavaScript as an inline module script,
     * with its config. It is a build of its own, so a viewer's page holds none
     * of the admin screens.
     */
    public static function viewerJs(SpectraDashboard $dashboard, Model $trackable): HtmlString
    {
        $jsPath = __DIR__.'/../../dist/viewer.js';

        if (File::exists($jsPath)) {
            $js = File::get($jsPath);
            $config = Js::from(static::viewerScriptVariables());
            $shared = Js::from([
                'baseUrl' => route('spectra.dashboards.show', ['id' => $dashboard->id], false),
                'dataUrl' => route('spectra.dashboards.data', ['id' => $dashboard->id], false),
                'period' => $dashboard->default_period,
                'name' => $dashboard->name,
                'trackableName' => isset($trackable->name) && is_string($trackable->name) ? $trackable->name : null,
                'sections' => $dashboard->enabledSections(),
                'showCosts' => $dashboard->show_costs,
                'showMessages' => $dashboard->show_messages,
            ]);

            return new HtmlString(<<<HTML
                <script type="module">
                    window.Spectra = {$config};
                    window.SpectraViewer = {$shared};
                    {$js}
                </script>
            HTML);
        }

        Log::warning("Spectra shared dashboard JavaScript not found at {$jsPath} — the page will not load. Run `npm run build` in the package to generate the dist assets.");

        return new HtmlString('');
    }

    /**
     * Get the config a shared dashboard's JavaScript reads from window.Spectra:
     * what formats numbers and dates, and nothing about the installation.
     *
     * @return array<string, mixed>
     */
    public static function viewerScriptVariables(): array
    {
        return [
            'path' => '',
            'timezone' => config('app.timezone', 'UTC'),
            'currency' => config('spectra.costs.currency', 'USD'),
            'currencySymbol' => config('spectra.costs.currency_symbol', '$'),
            'version' => '',
            'csrfToken' => null,
        ];
    }

    /**
     * Get the config the dashboard's JavaScript reads from window.Spectra.
     *
     * @return array<string, mixed>
     */
    public static function scriptVariables(): array
    {
        return [
            'path' => config('spectra.dashboard.path', 'spectra'),
            'timezone' => config('app.timezone', 'UTC'),
            'currency' => config('spectra.costs.currency', 'USD'),
            'currencySymbol' => config('spectra.costs.currency_symbol', '$'),
            'version' => static::version(),
            // The maintenance actions are POSTs behind the web middleware's CSRF check.
            'csrfToken' => rescue(fn () => csrf_token(), null, report: false),
        ];
    }

    /**
     * Get the installed Spectra version shown in the dashboard.
     */
    public static function version(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                return InstalledVersions::getPrettyVersion('spectra-php/laravel-spectra') ?? '1.0.0';
            } catch (\Exception) {
                return '1.0.0';
            }
        }

        return '1.0.0';
    }

    /**
     * Render the dashboard with the dark theme.
     */
    public static function useDarkTheme(): void
    {
        static::$useDarkTheme = true;
    }
}
