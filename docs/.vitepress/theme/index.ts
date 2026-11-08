import DefaultTheme from 'vitepress/theme'
import { inBrowser, useData } from 'vitepress'
import { h, watchEffect } from 'vue'
import './style.css'

// The chatvia command bar shows on the doc pages, not on the home page. The
// embed can't be removed and loaded again, so it loads once, on the first doc
// page opened, and is hidden by CSS (html.chatvia-hidden) while the home page shows.
function loadChatvia(): void {
  if (document.getElementById('chatbot-embed-script')) {
    return
  }

  const script = document.createElement('script')
  script.id = 'chatbot-embed-script'
  script.src = 'https://chat.chatvia.ai/shakomako/embed.js'
  script.defer = true
  script.dataset.agentId = 'b815114397aaef'
  script.dataset.mode = 'bar'
  document.body.appendChild(script)
}

export default {
  extends: DefaultTheme,
  setup() {
    const { frontmatter } = useData()

    if (inBrowser) {
      watchEffect(() => {
        const isHome = frontmatter.value.layout === 'home'

        document.documentElement.classList.toggle('chatvia-hidden', isHome)

        if (!isHome) {
          loadChatvia()
        }
      })
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
