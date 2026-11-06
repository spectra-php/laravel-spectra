import type { SpectraConfig, SpectraViewerConfig } from './app';

declare global {
    interface Window {
        Spectra: SpectraConfig;
        // Set only on a shared dashboard's page.
        SpectraViewer: SpectraViewerConfig;
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $spectra: SpectraConfig;
    }

    interface GlobalDirectives {
        vTooltip: typeof import('../directives/tooltip').default;
    }
}

export {};
