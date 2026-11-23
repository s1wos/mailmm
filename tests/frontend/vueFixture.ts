import { after, before } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer, type ViteDevServer } from 'vite'
import { createRenderer, createSSRApp, nodeOps, patchProp, ssrContextKey, type App, type Plugin, type SetupContext } from 'vue'
import { renderToString } from 'vue/server-renderer'

let vite: ViteDevServer
before(async () => {
    vite = await createServer({
        configFile: false,
        plugins: [vue()],
        optimizeDeps: { noDiscovery: true },
        server: { middlewareMode: true, ws: false, watch: null },
    })
})
after(() => vite?.close())

export async function loadComponent(path: string) {
    return (await vite.ssrLoadModule(path)).default
}

// Run lifecycle hooks once, then render snapshots of that same setup state.
export function mountSsrComponent<State extends object>(
    component: { setup(props: Record<string, unknown>, context: SetupContext): State },
    props: Record<string, unknown>,
    ...plugins: Plugin[]
) {
    function withPlugins(app: App) {
        for (const plugin of plugins) app.use(plugin)
        return app
    }
    let state!: State
    const renderer = createRenderer({ ...nodeOps, patchProp, createComment: () => ({} as Comment), insert() {}, remove() {} })
    const app = withPlugins(renderer.createApp({
        setup(_, context) {
            state = component.setup(props, context)
            return () => null
        },
    }).provide(ssrContextKey, {}))
    app.mount({} as Element)
    let mounted = true
    return {
        state,
        html: () => renderToString(withPlugins(createSSRApp({ ...component, setup: () => state }, props))),
        unmount() {
            if (mounted) app.unmount()
            mounted = false
        },
    }
}
