import type { RouteRecordRaw } from 'vue-router';
import Dashboard from './screens/dashboard/index.vue';
import Requests from './screens/requests/index.vue';
import RequestPreview from './screens/requests/preview.vue';
import Traces from './screens/traces/index.vue';
import TraceDetail from './screens/traces/detail.vue';
import Conversations from './screens/conversations/index.vue';
import ConversationDetail from './screens/conversations/detail.vue';
import Costs from './screens/costs/index.vue';
import Prompts from './screens/prompts/index.vue';
import Errors from './screens/errors/index.vue';
import Evals from './screens/evals/index.vue';
import EvalRun from './screens/evals/run.vue';
import Dataset from './screens/evals/dataset.vue';
import Playground from './screens/playground/index.vue';
import PlaygroundCall from './screens/playground/call.vue';
import Trackables from './screens/trackables/index.vue';
import TrackableDetail from './screens/trackables/detail.vue';
import Users from './screens/users/index.vue';
import Dashboards from './screens/dashboards/index.vue';
import DashboardEdit from './screens/dashboards/edit.vue';
import DashboardPreview from './screens/dashboards/preview.vue';
import Maintenance from './screens/maintenance/index.vue';
import Review from './screens/review/index.vue';
import ReviewQueue from './screens/review/queue.vue';

const routes: RouteRecordRaw[] = [
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
        path: '/errors',
        name: 'errors',
        component: Errors,
    },
    {
        path: '/evals',
        name: 'evals',
        component: Evals,
    },
    {
        path: '/evals/runs/:id',
        name: 'eval-run',
        component: EvalRun,
    },
    {
        path: '/evals/datasets/:id',
        name: 'eval-dataset',
        component: Dataset,
    },
    {
        path: '/playground',
        name: 'playground',
        component: Playground,
    },
    {
        path: '/playground/new',
        name: 'playground-new',
        component: PlaygroundCall,
    },
    {
        path: '/playground/:id',
        name: 'playground-call',
        component: PlaygroundCall,
    },
    {
        path: '/users',
        name: 'users',
        component: Users,
    },
    {
        path: '/users/view/:id',
        name: 'user-detail',
        component: TrackableDetail,
    },
    {
        path: '/dashboards',
        name: 'dashboards',
        component: Dashboards,
    },
    {
        path: '/dashboards/new',
        name: 'dashboards-create',
        component: DashboardEdit,
    },
    {
        path: '/dashboards/:id/edit',
        name: 'dashboards-edit',
        component: DashboardEdit,
    },
    {
        path: '/dashboards/:id/preview',
        name: 'dashboards-preview',
        component: DashboardPreview,
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
        path: '/review',
        name: 'review',
        component: Review,
    },
    {
        path: '/review/:queue',
        name: 'review-queue',
        component: ReviewQueue,
    },
    {
        path: '/maintenance',
        name: 'maintenance',
        component: Maintenance,
    },
];

export default routes;
