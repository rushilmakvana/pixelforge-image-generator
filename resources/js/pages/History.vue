<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { usePollWhile } from '@/composables/usePollWhile';
import AppLayout from '@/layouts/AppLayout.vue';
import { isActive, type BreadcrumbItem, type Generation } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    generations: {
        data: Generation[];
        links: { prev: string | null; next: string | null };
        page: number;
        last_page: number;
        total: number;
    };
    totals: { charged: string; succeeded: number; failed: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/history' }];

const anyActive = computed(() => props.generations.data.some(isActive));
usePollWhile(anyActive, ['generations', 'totals']);
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 lg:p-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="text-sm text-muted-foreground">Total spent</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ totals.charged }}</div>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="text-sm text-muted-foreground">Succeeded</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ totals.succeeded }}</div>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="text-sm text-muted-foreground">Failed (free)</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ totals.failed }}</div>
                </div>
            </div>

            <div v-if="generations.data.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard v-for="g in generations.data" :key="g.id" :generation="g" />
            </div>
            <p v-else class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                No generations yet. <Link :href="route('generate')" class="underline">Create one</Link>.
            </p>

            <div v-if="generations.last_page > 1" class="flex items-center justify-between">
                <Button variant="outline" :disabled="!generations.links.prev" as-child>
                    <Link :href="generations.links.prev ?? '#'" preserve-scroll>Newer</Link>
                </Button>
                <span class="text-sm text-muted-foreground">Page {{ generations.page }} of {{ generations.last_page }}</span>
                <Button variant="outline" :disabled="!generations.links.next" as-child>
                    <Link :href="generations.links.next ?? '#'" preserve-scroll>Older</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
