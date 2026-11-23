<script setup lang="ts">
import type { GenerationStatus } from '@/types';
import { CheckCircle2, Clock, Loader2, Send, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus }>();

const config = {
    pending: { label: 'Submitting', icon: Send, spin: false, cls: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
    queued: { label: 'Queued', icon: Clock, spin: false, cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    processing: { label: 'Processing', icon: Loader2, spin: true, cls: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300' },
    succeeded: {
        label: 'Succeeded',
        icon: CheckCircle2,
        spin: false,
        cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    },
    failed: { label: 'Failed', icon: XCircle, spin: false, cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
} as const;

const c = computed(() => config[props.status]);
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', c.cls]">
        <component :is="c.icon" :class="['size-3.5', c.spin && 'animate-spin']" />
        {{ c.label }}
    </span>
</template>
