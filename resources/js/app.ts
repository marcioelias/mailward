import { createInertiaApp } from '@inertiajs/vue3'
import { startMyEyes } from '@my-eyes/core'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import type { DefineComponent } from 'vue'
import { createApp, h } from 'vue'
import '../css/app.css'

const appName = 'Mailward'

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
    progress: {
        color: '#4f46e5',
    },
})

/*
 * The design system's behaviour — theme switching, dropdowns, toasts — is
 * framework-free TypeScript bound to markup rather than Vue components, so it
 * is started once for the whole application. Idempotent by design.
 */
startMyEyes()
