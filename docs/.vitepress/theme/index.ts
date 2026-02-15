import DefaultTheme from 'vitepress/theme'
import { h } from 'vue'
import './style.css'

export default {
  extends: DefaultTheme,
  Layout() {
    return h(DefaultTheme.Layout, null, {
      'layout-bottom': () =>
        h('div', { class: 'footer-logo' }, [
          h('img', { src: '/images/logo.svg', alt: 'Laravel Spectra' })
        ])
    })
  }
}
