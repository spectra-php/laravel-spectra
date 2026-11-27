import DefaultTheme from 'vitepress/theme'
import { inBrowser } from 'vitepress'
import { h } from 'vue'
import './style.css'

// The chatvia chat widget shows on every page. Page changes don't reload the
// page, so the embed is added once, when the site first opens.
function loadChatvia(): void {
  if (document.getElementById('chatbot-embed-script')) {
    return
  }

  const script = document.createElement('script')
  script.id = 'chatbot-embed-script'
  script.src = 'https://chat.chatvia.ai/shakomako/embed.js'
  script.defer = true
  script.dataset.agentId = 'b815114397aaef'
  script.dataset.language = 'auto'
  script.dataset.theme = 'auto'
  document.body.appendChild(script)
}

export default {
  extends: DefaultTheme,
  setup() {
    if (inBrowser) {
      loadChatvia()
    }
  },
  Layout() {
    return h(DefaultTheme.Layout, null, {
      'layout-bottom': () =>
        h('div', { class: 'footer-logo' }, [
          h('img', { src: '/images/logo.svg', alt: 'Laravel Spectra' })
        ])
    })
  }
}
