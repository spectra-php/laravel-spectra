<?php

namespace Spectra\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

class DashboardAssets
{
    public static bool $useDarkTheme = false;

    public static function favicons(): HtmlString
    {
        $distPath = __DIR__.'/../../resources/images/favicons';
        $icons = [
            ['file' => 'favicon.svg', 'rel' => 'icon', 'type' => 'image/svg+xml', 'sizes' => null],
            ['file' => 'favicon.ico', 'rel' => 'icon', 'type' => 'image/x-icon', 'sizes' => null],
            ['file' => 'favicon-32x32.png', 'rel' => 'icon', 'type' => 'image/png', 'sizes' => '32x32'],
            ['file' => 'favicon-16x16.png', 'rel' => 'icon', 'type' => 'image/png', 'sizes' => '16x16'],
            ['file' => 'apple-touch-icon.png', 'rel' => 'apple-touch-icon', 'type' => 'image/png', 'sizes' => '180x180'],
        ];

        $tags = [];

        foreach ($icons as $icon) {
            $filePath = $distPath.'/'.$icon['file'];

            if (! file_exists($filePath)) {
                continue;
            }

            $content = file_get_contents($filePath);

            if ($content === false) {
                continue;
            }

            $href = 'data:'.$icon['type'].';base64,'.base64_encode($content);
            $sizes = $icon['sizes'] ? ' sizes="'.$icon['sizes'].'"' : '';
            $tags[] = '<link rel="'.$icon['rel'].'" type="'.$icon['type'].'"'.$sizes.' href="'.$href.'">';
        }

        return new HtmlString(implode("\n", $tags));
    }

    public static function css(): HtmlString
    {
        $cssPath = __DIR__.'/../../dist/app.css';

        if (file_exists($cssPath)) {
            $css = file_get_contents($cssPath);

            return new HtmlString(<<<HTML
                <style>{$css}</style>
            HTML);
        }

        return new HtmlString('');
    }

    public static function js(): HtmlString
    {
        $jsPath = __DIR__.'/../../dist/app.js';

        if (file_exists($jsPath)) {
            $js = file_get_contents($jsPath);
            $config = Js::from(static::scriptVariables());

            return new HtmlString(<<<HTML
                <script type="module">
                    window.Spectra = {$config};
                    {$js}
                </script>
            HTML);
        }

        return new HtmlString('');
    }

    /**
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
        ];
    }

    public static function version(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                return \Composer\InstalledVersions::getPrettyVersion('spectra-php/laravel-spectra') ?? '1.0.0';
            } catch (\Exception) {
                return '1.0.0';
            }
        }

        return '1.0.0';
    }

    public static function useDarkTheme(): void
    {
        static::$useDarkTheme = true;
    }
}
