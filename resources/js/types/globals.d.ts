import type { SpectraConfig } from './app';

declare global {
    interface Window {
        Spectra: SpectraConfig;
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
