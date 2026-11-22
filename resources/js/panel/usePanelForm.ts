import { onScopeDispose, reactive } from 'vue'
import { panelRequest, PanelHttpError } from './http.ts'
import { useActionGuard } from '../useActionGuard.ts'

interface FormResult {
    message?: string
    redirect?: string
}

export function usePanelForm(isAdmin: () => boolean = () => false, onInvalid?: (error: PanelHttpError) => void) {
    const guard = useActionGuard(isAdmin)
    const state = reactive({
        pending: false,
        action: '',
        fields: {} as Record<string, string[]>,
        message: '',
    })
    const lifetime = new AbortController()
    let editVersion = 0
    onScopeDispose(() => lifetime.abort())

    function edited() {
        editVersion++
        if (Object.keys(state.fields).length) guard.reset()
    }

    async function submit(event: SubmitEvent, method = 'POST'): Promise<FormResult | undefined> {
        if (state.pending || lifetime.signal.aborted) return
        const element = event.currentTarget as HTMLFormElement
        const action = element.getAttribute('action')!
        if (guard.blocked(action)) return
        const values = Object.fromEntries(new FormData(element))
        const version = editVersion
        state.pending = true
        state.action = action
        state.fields = {}
        state.message = ''
        try {
            const result = await panelRequest<FormResult>(action, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(values),
                signal: lifetime.signal,
            })
            if (!lifetime.signal.aborted) {
                guard.pause(action, 1000)
                return result
            }
        } catch (error) {
            if (lifetime.signal.aborted) return
            if (error instanceof PanelHttpError && error.status === 422) {
                state.fields = error.errors
                onInvalid?.(error)
                if (version === editVersion) guard.pause(action, 1000)
                if (Object.keys(state.fields).length === 0) state.message = 'Проверьте введённые данные.'
            } else {
                state.message = error instanceof PanelHttpError ? error.message
                    : 'Не удалось отправить форму. Проверьте соединение и повторите попытку.'
            }
        } finally {
            state.pending = false
        }
    }

    function messageFor(action: string) {
        return state.action === action ? state.message : ''
    }

    return { state, submit, messageFor, blocked: guard.blocked, edited }
}
