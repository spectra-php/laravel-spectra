import Dashboard from './screens/dashboard/index.vue';
import Requests from './screens/requests/index.vue';
import RequestPreview from './screens/requests/preview.vue';
import Traces from './screens/traces/index.vue';
import TraceDetail from './screens/traces/detail.vue';
import Conversations from './screens/conversations/index.vue';
import ConversationDetail from './screens/conversations/detail.vue';
import Costs from './screens/costs/index.vue';
import Prompts from './screens/prompts/index.vue';
import Trackables from './screens/trackables/index.vue';
import TrackableDetail from './screens/trackables/detail.vue';
import Maintenance from './screens/maintenance/index.vue';

export default [
    {
        path: '/',
        name: 'dashboard',
        component: Dashboard,
    },
    {
        path: '/requests',
        name: 'requests',
        component: Requests,
    },
    {
        path: '/requests/:id',
        name: 'request-preview',
        component: RequestPreview,
    },
    {
        path: '/traces',
        name: 'traces',
        component: Traces,
    },
    {
        path: '/traces/:traceId',
        name: 'trace-detail',
        component: TraceDetail,
    },
    {
        path: '/conversations',
        name: 'conversations',
        component: Conversations,
    },
    {
        path: '/conversations/:conversationId',
        name: 'conversation-detail',
        component: ConversationDetail,
    },
    {
        path: '/costs',
        name: 'costs',
        component: Costs,
    },
    {
        path: '/prompts',
        name: 'prompts',
        component: Prompts,
    },
    {
        path: '/trackables',
        name: 'trackables',
        component: Trackables,
    },
    {
        path: '/trackables/view/:id',
        name: 'trackable-detail',
        component: TrackableDetail,
    },
    {
        path: '/maintenance',
        name: 'maintenance',
        component: Maintenance,
    },
];
