import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * Re-fetch the given Inertia props every `intervalMs` while `active` is true.
 * Used for live generation status: stops automatically once everything is final.
 */
export function usePollWhile(active: Ref<boolean>, only: string[], intervalMs = 2500) {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;

    const tick = () => {
        timer = setTimeout(() => {
            if (document.hidden) {
                return tick(); // don't hammer the server from background tabs
            }
            router.reload({
                only: [...only, 'balance'],
                onFinish: () => {
                    if (!stopped && active.value) tick();
                    else timer = null;
                },
            });
        }, intervalMs);
    };

    watch(
        active,
        (isActive) => {
            if (isActive && timer === null) tick();
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        stopped = true;
        if (timer) clearTimeout(timer);
    });
}
