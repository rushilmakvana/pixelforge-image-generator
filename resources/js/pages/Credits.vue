<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Coins } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    packs: number[];
    transactions: {
        id: number;
        type: 'purchase' | 'hold' | 'release' | 'charge';
        amount: string;
        positive: boolean;
        description: string | null;
        generation_id: number | null;
        created_at: string;
    }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];
const page = usePage<SharedData>();
const buying = ref<number | null>(null);

const buy = (dollars: number) => {
    buying.value = dollars;
    router.post(route('credits.store'), { dollars }, { preserveScroll: true, onFinish: () => (buying.value = null) });
};

const typeLabel = { purchase: 'Purchase', hold: 'Reserved', release: 'Reservation released', charge: 'Charge' };
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-4xl flex-col gap-6 p-4 lg:p-6">
            <div
                class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div class="text-sm text-muted-foreground">Available balance</div>
                    <div class="text-4xl font-semibold tabular-nums">{{ page.props.balance?.formatted }}</div>
                    <p class="mt-1 text-xs text-muted-foreground">1 credit = $1. Purchases here are simulated, no payment is taken.</p>
                </div>
                <div class="flex gap-2">
                    <Button v-for="p in packs" :key="p" :disabled="buying !== null" @click="buy(p)"> <Coins /> Buy ${{ p }} </Button>
                </div>
            </div>
            <p v-if="page.props.flash.success" class="text-sm text-emerald-700 dark:text-emerald-400">{{ page.props.flash.success }}</p>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <h2 class="border-b px-5 py-3 font-medium">Ledger</h2>
                <p class="px-5 pt-3 text-xs text-muted-foreground">
                    When you submit a generation we reserve its maximum possible cost. When it finishes, the reservation is released and the actual
                    cost is charged. Failed generations are only reserved, then released.
                </p>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2 font-medium">When</th>
                            <th class="px-5 py-2 font-medium">Type</th>
                            <th class="px-5 py-2 font-medium">Details</th>
                            <th class="px-5 py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="t in transactions" :key="t.id">
                            <td class="whitespace-nowrap px-5 py-2 text-muted-foreground">{{ new Date(t.created_at).toLocaleString() }}</td>
                            <td class="px-5 py-2">{{ typeLabel[t.type] }}</td>
                            <td class="px-5 py-2 text-muted-foreground">{{ t.description }}</td>
                            <td :class="['px-5 py-2 text-right tabular-nums', t.positive ? 'text-emerald-700 dark:text-emerald-400' : '']">
                                {{ t.positive ? '+' : '' }}{{ t.amount }}
                            </td>
                        </tr>
                        <tr v-if="!transactions.length">
                            <td colspan="4" class="px-5 py-6 text-center text-muted-foreground">No transactions yet.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
