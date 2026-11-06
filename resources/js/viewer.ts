import { createApp } from 'vue';
import DashboardPage from './components/dashboards/DashboardPage.vue';
import tooltip from './directives/tooltip';

// The page has no theme switch, so it follows the viewer's system.
document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);

const app = createApp(DashboardPage);

app.directive('tooltip', tooltip);
app.mount('#spectra');
