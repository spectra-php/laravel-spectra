import Dashboard from './screens/dashboard/index.vue';
import Requests from './screens/requests/index.vue';
import RequestPreview from './screens/requests/preview.vue';
import Costs from './screens/costs/index.vue';
import Trackables from './screens/trackables/index.vue';
import TrackableDetail from './screens/trackables/detail.vue';

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
        path: '/costs',
        name: 'costs',
        component: Costs,
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
];
