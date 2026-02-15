import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel Spectra',
  description: 'Comprehensive observability for AI and LLM operations in Laravel applications.',
  themeConfig: {
    logo: {
      src: '/images/logo.svg',
      alt: 'Laravel Spectra'
    },
    siteTitle: false,
    nav: [
      { text: 'Home', link: '/' },
      { text: 'Documentation', link: '/introduction' },
      { text: 'Configuration', link: '/configuration' }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Introduction', link: '/introduction' },
          { text: 'Installation', link: '/installation' }
        ]
      },
      {
        text: 'Core Concepts',
        items: [
          { text: 'Dashboard', link: '/dashboard' },
          { text: 'Usage', link: '/usage' },
          { text: 'Providers', link: '/providers' },
          { text: 'Models', link: '/models' },
          { text: 'Pricing', link: '/pricing' },
          { text: 'Costs', link: '/costs' },
          { text: 'Budgets', link: '/budgets' },
          { text: 'Tags', link: '/tags' },
          { text: 'Metadata', link: '/metadata' }
        ]
      },
      {
        text: 'Integrations',
        items: [
          { text: 'OpenTelemetry', link: '/opentelemetry' }
        ]
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Testing', link: '/testing' },
          { text: 'Custom Providers', link: '/custom-providers' },
          { text: 'Artisan Commands', link: '/commands' },
          { text: 'Configuration', link: '/configuration' },
          { text: 'Troubleshooting', link: '/troubleshooting' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/spectra-php/laravel-spectra' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026-present Ahmad Mayahi'
    }
  }
})
