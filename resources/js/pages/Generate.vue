<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePollWhile } from '@/composables/usePollWhile';
import AppLayout from '@/layouts/AppLayout.vue';
import { isActive, type BreadcrumbItem, type Generation, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Info, Loader2, Plus, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    generations: Generation[];
}>();

interface Estimate {
    min: string;
    expected: string;
    max: string;
    max_nanos: number;
    affordable: boolean;
    tokens: {
        input_text_tokens: { min: number; expected: number; max: number };
        input_image_tokens: number;
        output_tokens: number;
        reasoning_tokens: { min: number; expected: number; max: number };
    };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];
const page = usePage<SharedData>();

const sizeLabels: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Unicode code points, same as PHP's mb_strlen and Pixelforge's character count.
const chars = computed(() => [...form.prompt].length);
const referenceCount = computed(() => form.reference_images.filter((u) => u.trim() !== '').length);

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);

async function refreshEstimate() {
    estimating.value = true;
    try {
        const params = new URLSearchParams({
            chars: String(chars.value),
            size: form.size,
            quality: form.quality,
            references: String(referenceCount.value),
        });
        const res = await fetch(`${route('generations.estimate')}?${params}`, { headers: { Accept: 'application/json' } });
        if (res.ok) estimate.value = await res.json();
    } finally {
        estimating.value = false;
    }
}

watchDebounced([chars, () => form.size, () => form.quality, referenceCount, () => page.props.balance?.nanos], refreshEstimate, {
    debounce: 250,
    immediate: true,
});

const addReference = () => form.reference_images.length < 4 && form.reference_images.push('');
const removeReference = (i: number) => form.reference_images.splice(i, 1);

const referenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([k]) => k.startsWith('reference_images'))
        .map(([, v]) => v),
);

const canSubmit = computed(() => form.prompt.trim() !== '' && !form.processing && estimate.value?.affordable !== false);

const submit = () =>
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt'),
    });

const anyActive = computed(() => props.generations.some(isActive));
usePollWhile(anyActive, ['generations']);
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-8 p-4 lg:p-6">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                <!-- Form -->
                <form class="flex flex-col gap-6 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <div class="flex items-baseline justify-between">
                            <Label for="prompt">Prompt</Label>
                            <span class="text-xs tabular-nums text-muted-foreground" :class="{ 'text-red-600': chars > 4000 }"
                                >{{ chars }} / 4000</span
                            >
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="5"
                            maxlength="4000"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            @keydown.meta.enter="submit"
                            @keydown.ctrl.enter="submit"
                        />
                        <InputError :message="form.errors.prompt" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">Size</legend>
                            <div class="grid grid-cols-3 gap-2">
                                <label
                                    v-for="size in sizes"
                                    :key="size"
                                    :class="[
                                        'flex cursor-pointer flex-col items-center gap-1 rounded-md border px-2 py-2 text-xs transition',
                                        form.size === size ? 'border-primary bg-primary/5 font-medium' : 'border-input hover:bg-accent',
                                    ]"
                                >
                                    <input v-model="form.size" type="radio" :value="size" class="sr-only" />
                                    <span>{{ sizeLabels[size] ?? size }}</span>
                                    <span class="text-[10px] text-muted-foreground">{{ size }}</span>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">Quality</legend>
                            <div class="grid grid-cols-3 gap-2">
                                <label
                                    v-for="quality in qualities"
                                    :key="quality"
                                    :class="[
                                        'flex cursor-pointer items-center justify-center rounded-md border px-2 py-2 text-xs capitalize transition',
                                        form.quality === quality ? 'border-primary bg-primary/5 font-medium' : 'border-input hover:bg-accent',
                                    ]"
                                >
                                    <input v-model="form.quality" type="radio" :value="quality" class="sr-only" />
                                    {{ quality }}
                                </label>
                            </div>
                        </fieldset>
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label>Reference images <span class="font-normal text-muted-foreground">(optional, up to 4)</span></Label>
                            <Button type="button" variant="ghost" size="sm" :disabled="form.reference_images.length >= 4" @click="addReference">
                                <Plus /> Add URL
                            </Button>
                        </div>
                        <div v-for="(_, i) in form.reference_images" :key="i" class="flex gap-2">
                            <Input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/style.png" />
                            <Button type="button" variant="ghost" size="icon" aria-label="Remove" @click="removeReference(i)"><X /></Button>
                        </div>
                        <InputError v-for="(msg, i) in referenceErrors" :key="i" :message="msg" />
                    </div>

                    <InputError :message="(form.errors as Record<string, string>).credits" />

                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="submit" :disabled="!canSubmit">
                            <Loader2 v-if="form.processing" class="animate-spin" />
                            <Sparkles v-else />
                            Generate<span v-if="estimate"> · ~{{ estimate.expected }}</span>
                        </Button>
                        <span class="text-xs text-muted-foreground">Ctrl/⌘ + Enter</span>
                        <span v-if="page.props.flash.success" class="text-sm text-emerald-700 dark:text-emerald-400">{{
                            page.props.flash.success
                        }}</span>
                    </div>
                </form>

                <!-- Cost -->
                <aside class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div>
                        <h2 class="text-sm font-medium text-muted-foreground">Estimated cost</h2>
                        <p class="mt-1 text-3xl font-semibold tabular-nums" :class="{ 'opacity-50': estimating }">~{{ estimate?.expected ?? '—' }}</p>
                        <p v-if="estimate" class="text-sm tabular-nums text-muted-foreground">between {{ estimate.min }} and {{ estimate.max }}</p>
                    </div>

                    <table v-if="estimate" class="w-full text-xs">
                        <tbody class="divide-y divide-border">
                            <tr>
                                <td class="py-1.5 text-muted-foreground">Output</td>
                                <td class="py-1.5 text-right tabular-nums">{{ estimate.tokens.output_tokens.toLocaleString() }} tokens</td>
                            </tr>
                            <tr>
                                <td class="py-1.5 text-muted-foreground">Reasoning</td>
                                <td class="py-1.5 text-right tabular-nums">
                                    {{ estimate.tokens.reasoning_tokens.min.toLocaleString() }}–{{
                                        estimate.tokens.reasoning_tokens.max.toLocaleString()
                                    }}
                                    tokens
                                </td>
                            </tr>
                            <tr>
                                <td class="py-1.5 text-muted-foreground">Prompt text</td>
                                <td class="py-1.5 text-right tabular-nums">
                                    ~{{ estimate.tokens.input_text_tokens.expected.toLocaleString() }} tokens
                                </td>
                            </tr>
                            <tr>
                                <td class="py-1.5 text-muted-foreground">Reference images</td>
                                <td class="py-1.5 text-right tabular-nums">{{ estimate.tokens.input_image_tokens.toLocaleString() }} tokens</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="flex gap-2 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        <p>
                            Reasoning and exact prompt tokens are only known once the image is done, so you're charged the
                            <strong>actual</strong> cost (Pixelforge's price + 25%) when it finishes. We hold the maximum until then. Failed
                            generations are free.
                        </p>
                    </div>

                    <div class="mt-auto flex items-center justify-between border-t pt-4 text-sm">
                        <span class="text-muted-foreground">Your balance</span>
                        <span class="font-semibold tabular-nums">{{ page.props.balance?.formatted }}</span>
                    </div>
                    <div
                        v-if="estimate && !estimate.affordable"
                        class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        This generation can cost up to {{ estimate.max }}, which is more than your balance.
                        <Link :href="route('credits')" class="font-medium underline">Buy credits</Link>
                    </div>
                </aside>
            </div>

            <section class="flex flex-col gap-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-lg font-semibold">Recent generations</h2>
                    <Link :href="route('history')" class="text-sm text-muted-foreground hover:underline">View all</Link>
                </div>
                <div v-if="generations.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <GenerationCard v-for="g in generations" :key="g.id" :generation="g" />
                </div>
                <p v-else class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Nothing yet. Write a prompt above to create your first image.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
