import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import DashboardPage from './components/dashboards/DashboardPage.vue';
import OverviewSection from './components/dashboards/sections/OverviewSection.vue';
import RequestsSection from './components/dashboards/sections/RequestsSection.vue';
import TracesSection from './components/dashboards/sections/TracesSection.vue';
import TraceSection from './components/dashboards/sections/TraceSection.vue';
import ConversationsSection from './components/dashboards/sections/ConversationsSection.vue';
import ConversationSection from './components/dashboards/sections/ConversationSection.vue';
import ErrorsSection from './components/dashboards/sections/ErrorsSection.vue';
import CostsSection from './components/dashboards/sections/CostsSection.vue';
import tooltip from './directives/tooltip';

// The page has no theme switch, so it follows the viewer's system.
document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);

const router = createRouter({
    history: createWebHistory(window.SpectraViewer.baseUrl),
    routes: [
        { path: '/', name: 'overview', component: OverviewSection },
        { path: '/requests', name: 'requests', component: RequestsSection },
        { path: '/traces', name: 'traces', component: TracesSection },
        { path: '/traces/:traceId', name: 'trace', component: TraceSection },
        { path: '/conversations', name: 'conversations', component: ConversationsSection },
        { path: '/conversations/:conversationId(.*)', name: 'conversation', component: ConversationSection },
        { path: '/errors', name: 'errors', component: ErrorsSection },
        { path: '/costs', name: 'costs', component: CostsSection },
    ],
});

const app = createApp(DashboardPage);

app.directive('tooltip', tooltip);
app.use(router);
app.mount('#spectra');
