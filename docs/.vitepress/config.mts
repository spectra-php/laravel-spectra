import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel Spectra',
  description: 'Observability for AI and LLM operations in Laravel applications.',
  themeConfig: {
    logo: {
      src: '/images/logo.svg',
      alt: 'Laravel Spectra'
    },
    siteTitle: false,
    nav: [
      { text: 'Hire Me', link: 'https://mayahi.net/services' },
      { text: 'Home', link: '/' },
      { text: 'Documentation', link: '/introduction' },
      { text: 'Configuration', link: '/configuration' }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Introduction', link: '/introduction' },
          { text: 'Installation', link: '/installation' },
          { text: 'Upgrade Guide', link: '/upgrade' }
        ]
      },
      {
        text: 'Tracking',
        items: [
          { text: 'Usage', link: '/usage' },
          { text: 'Dashboard', link: '/dashboard' },
          { text: 'Dashboards', link: '/dashboards' },
          { text: 'Models', link: '/models' },
          { text: 'Tags', link: '/tags' },
          { text: 'Metadata', link: '/metadata' },
          { text: 'Tools', link: '/tools' }
        ]
      },
      {
        text: 'Costs',
        items: [
          { text: 'Pricing', link: '/pricing' },
          { text: 'Costs', link: '/costs' },
          { text: 'Budgets', link: '/budgets' },
          { text: 'Alerts', link: '/alerts' }
        ]
      },
      {
        text: 'Quality',
        items: [
          { text: 'Scores', link: '/scores' },
          { text: 'Prompts', link: '/prompts' },
          { text: 'Annotation Queues', link: '/annotations' },
          { text: 'Evaluations', link: '/evaluations' },
          { text: 'Playground', link: '/playground' }
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
          { text: 'Migrations', link: '/migrations' },
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
