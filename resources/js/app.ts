import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'Transcritor Web';

void createInertiaApp({
    pages: './pages',
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#2563eb',
    },
    setup({ el, App, props, plugin }) {
        if (!el) {
            throw new Error('Inertia root element was not found.');
        }

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
