<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import type { Generation } from '@/types';
import { AlertTriangle, ImageOff, Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

const imageFailed = ref(false);
const g = computed(() => props.generation);
const [w, h] = props.generation.size.split('x').map(Number);
const aspect = `${w} / ${h}`;

const created = computed(() => new Date(g.value.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }));
const tokenSummary = computed(() => {
    const u = g.value.usage;
    if (!u) return null;
    return `${u.input_text_tokens} text · ${u.input_image_tokens} image · ${u.output_tokens} output · ${u.reasoning_tokens} reasoning tokens`;
});
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
        <div class="relative flex items-center justify-center bg-muted/50" :style="{ aspectRatio: aspect }">
            <template v-if="g.status === 'succeeded' && g.image_url && !imageFailed && !g.image_expired">
                <a :href="g.image_url" target="_blank" rel="noopener" class="block size-full">
                    <img :src="g.image_url" :alt="g.prompt" class="size-full object-cover" loading="lazy" @error="imageFailed = true" />
                </a>
            </template>
            <div v-else-if="g.status === 'succeeded'" class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground">
                <ImageOff class="size-6" />
                Image link expired (Pixelforge keeps images for 24 hours).
            </div>
            <div v-else-if="g.status === 'failed'" class="flex flex-col items-center gap-2 p-4 text-center text-sm text-red-700 dark:text-red-300">
                <AlertTriangle class="size-6" />
                <span>{{ g.error_message ?? 'Generation failed.' }}</span>
                <span class="text-xs text-muted-foreground">No charge.</span>
            </div>
            <div v-else class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground">
                <Loader2 class="size-6 animate-spin" />
                <span v-if="g.status === 'pending'">{{ g.status_note ?? 'Sending to Pixelforge…' }}</span>
                <span v-else-if="g.status === 'queued'">Waiting in Pixelforge's queue…</span>
                <span v-else>Generating — usually 5–30 seconds…</span>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-2">
                <p class="line-clamp-3 text-sm" :title="g.prompt">{{ g.prompt }}</p>
                <StatusBadge :status="g.status" class="shrink-0" />
            </div>

            <div class="text-xs text-muted-foreground">
                {{ g.size }} · {{ g.quality }} quality<span v-if="g.reference_images.length">
                    · {{ g.reference_images.length }} reference image{{ g.reference_images.length > 1 ? 's' : '' }}</span
                >
                · {{ created }}
            </div>

            <dl class="mt-auto grid grid-cols-2 gap-2 rounded-lg bg-muted/40 p-3 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="font-medium tabular-nums" :title="`Range ${g.estimated_min} – ${g.estimated_max}`">~{{ g.estimated }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Actual cost</dt>
                    <dd class="font-medium tabular-nums">
                        <span v-if="g.charged" :title="tokenSummary ? `${g.charged_exact} USD — ${tokenSummary}` : (g.charged_exact ?? '')">{{
                            g.charged
                        }}</span>
                        <span v-else-if="g.status === 'failed'" class="text-emerald-700 dark:text-emerald-400">$0.00 (free)</span>
                        <span v-else class="text-muted-foreground">pending…</span>
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
