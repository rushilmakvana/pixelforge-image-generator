<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, ChevronDown, ChevronRight, XCircle } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

interface Totals {
    count: number;
    charged: string;
    provider_cost: string;
    provider_cost_exact: string;
    profit: string;
}
interface UserRow extends Totals {
    user: string;
    email: string;
}
interface DayRow extends Totals {
    date: string;
    pixelforge_cost: string | null;
    pixelforge_generations: number | null;
    matches: boolean | null;
    users: UserRow[];
}

const props = defineProps<{
    start: string;
    end: string;
    report: {
        days: DayRow[];
        users: UserRow[];
        total: Totals & { pixelforge_cost: string | null; matches: boolean | null };
        usage_error: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Spend report', href: '/admin/report' }];
const range = reactive({ start: props.start, end: props.end });
const open = ref<Set<string>>(new Set());
const toggle = (d: string) => (open.value.has(d) ? open.value.delete(d) : open.value.add(d));

const apply = () => router.get(route('admin.report'), range, { preserveState: true });
</script>

<template>
    <Head title="Spend report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 lg:p-6">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
                <label class="grid gap-1 text-xs text-muted-foreground">From (UTC)<Input v-model="range.start" type="date" /></label>
                <label class="grid gap-1 text-xs text-muted-foreground">To (UTC)<Input v-model="range.end" type="date" /></label>
                <Button type="submit" variant="outline">Apply</Button>
                <p class="text-xs text-muted-foreground">
                    Days are UTC, by completion time, the same as Pixelforge's <code>/v1/usage</code>. Max 92 days.
                </p>
            </form>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <div class="text-sm text-muted-foreground">Charged to users</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ report.total.charged }}</div>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-sm text-muted-foreground">Provider cost (ours)</div>
                    <div class="text-2xl font-semibold tabular-nums" :title="report.total.provider_cost_exact">{{ report.total.provider_cost }}</div>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-sm text-muted-foreground">Pixelforge reports</div>
                    <div class="flex items-center gap-2 text-2xl font-semibold tabular-nums">
                        {{ report.total.pixelforge_cost ?? '—' }}
                        <CheckCircle2 v-if="report.total.matches === true" class="size-5 text-emerald-600" />
                        <XCircle v-else-if="report.total.matches === false" class="size-5 text-red-600" />
                    </div>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-sm text-muted-foreground">Profit</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ report.total.profit }}</div>
                </div>
            </div>
            <p v-if="report.usage_error" class="text-sm text-amber-700">{{ report.usage_error }}</p>

            <section class="overflow-x-auto rounded-xl border">
                <h2 class="border-b px-5 py-3 font-medium">By day</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2 font-medium">Date</th>
                            <th class="px-5 py-2 text-right font-medium">Generations</th>
                            <th class="px-5 py-2 text-right font-medium">Charged</th>
                            <th class="px-5 py-2 text-right font-medium">Provider cost</th>
                            <th class="px-5 py-2 text-right font-medium">Pixelforge /v1/usage</th>
                            <th class="px-5 py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="d in report.days" :key="d.date">
                            <tr class="cursor-pointer hover:bg-muted/40" @click="toggle(d.date)">
                                <td class="flex items-center gap-1 px-5 py-2 font-medium">
                                    <component :is="open.has(d.date) ? ChevronDown : ChevronRight" class="size-4" />{{ d.date }}
                                </td>
                                <td class="px-5 py-2 text-right tabular-nums">{{ d.count }}</td>
                                <td class="px-5 py-2 text-right tabular-nums">{{ d.charged }}</td>
                                <td class="px-5 py-2 text-right tabular-nums" :title="d.provider_cost_exact">{{ d.provider_cost }}</td>
                                <td class="px-5 py-2 text-right tabular-nums">
                                    <span class="inline-flex items-center gap-1">
                                        {{ d.pixelforge_cost ?? '—' }}
                                        <CheckCircle2 v-if="d.matches === true" class="size-4 text-emerald-600" />
                                        <XCircle
                                            v-else-if="d.matches === false"
                                            class="size-4 text-red-600"
                                            :title="`Pixelforge: ${d.pixelforge_generations} generations`"
                                        />
                                    </span>
                                </td>
                                <td class="px-5 py-2 text-right tabular-nums">{{ d.profit }}</td>
                            </tr>
                            <tr v-for="u in open.has(d.date) ? d.users : []" :key="d.date + u.email" class="bg-muted/30 text-xs">
                                <td class="px-5 py-1.5 pl-11">
                                    {{ u.user }} <span class="text-muted-foreground">{{ u.email }}</span>
                                </td>
                                <td class="px-5 py-1.5 text-right tabular-nums">{{ u.count }}</td>
                                <td class="px-5 py-1.5 text-right tabular-nums">{{ u.charged }}</td>
                                <td class="px-5 py-1.5 text-right tabular-nums">{{ u.provider_cost }}</td>
                                <td></td>
                                <td class="px-5 py-1.5 text-right tabular-nums">{{ u.profit }}</td>
                            </tr>
                        </template>
                        <tr v-if="!report.days.length">
                            <td colspan="6" class="px-5 py-6 text-center text-muted-foreground">No completed generations in this range.</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="overflow-x-auto rounded-xl border">
                <h2 class="border-b px-5 py-3 font-medium">By user</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2 font-medium">User</th>
                            <th class="px-5 py-2 text-right font-medium">Generations</th>
                            <th class="px-5 py-2 text-right font-medium">Charged</th>
                            <th class="px-5 py-2 text-right font-medium">Provider cost</th>
                            <th class="px-5 py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="u in report.users" :key="u.email">
                            <td class="px-5 py-2">
                                {{ u.user }} <span class="text-xs text-muted-foreground">{{ u.email }}</span>
                            </td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ u.count }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ u.charged }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ u.provider_cost }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ u.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
