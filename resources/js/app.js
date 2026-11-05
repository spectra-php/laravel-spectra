import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import routes from './routes';
import tooltip from './directives/tooltip';
import '../css/app.css';

const router = createRouter({
    history: createWebHistory('/' + window.Spectra.path),
    routes,
});

const app = createApp(App);

app.config.globalProperties.$spectra = window.Spectra;

app.directive('tooltip', tooltip);
app.use(router);
app.mount('#spectra');
