import { closeSync, openSync, readSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

const publicDir = fileURLToPath(new URL('../public', import.meta.url))
const pngSizes = new Map<string, { width: number; height: number } | null>()

// Width and height of a PNG in docs/public, read from its IHDR header.
function pngSize(src: string): { width: number; height: number } | null {
  if (!pngSizes.has(src)) {
    let size = null

    try {
      const header = Buffer.alloc(24)
      const fd = openSync(publicDir + src, 'r')
      readSync(fd, header, 0, 24, 0)
      closeSync(fd)
      size = { width: header.readUInt32BE(16), height: header.readUInt32BE(20) }
    } catch {
      // Not in docs/public: leave the tag alone.
    }

    pngSizes.set(src, size)
  }

  return pngSizes.get(src)!
}

// Give every <img> of a PNG its width and height, so the page keeps its layout
// while the screenshots load and a link to a #heading lands on the heading.
function sizeImages(html: string): string {
  return html.replace(/<img\b[^>]*>/g, (tag) => {
    const src = tag.match(/\ssrc="(\/[^"]+\.png)"/)?.[1]
    const size = src && !/\swidth=/.test(tag) ? pngSize(src) : null

    return size ? tag.replace(/<img\b/, `<img width="${size.width}" height="${size.height}"`) : tag
  })
}

export default defineConfig({
  title: 'Laravel Spectra',
  description: 'Observability for AI and LLM operations in Laravel applications.',
  cleanUrls: true,
  markdown: {
    config(md) {
      md.core.ruler.push('image_sizes', (state) => {
        for (const token of state.tokens) {
          if (token.type === 'html_block') {
            token.content = sizeImages(token.content)
          }

          for (const child of token.children ?? []) {
            if (child.type === 'html_inline') {
              child.content = sizeImages(child.content)
            }
          }
        }
      })
    }
  },
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
          { text: 'Shared Dashboards', link: '/dashboards' },
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
          { text: 'Datasets', link: '/datasets' },
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
          { text: 'Running at High Volume', link: '/production' },
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
