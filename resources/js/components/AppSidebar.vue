<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Coins, History, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems: NavItem[] = [
    { title: 'Generate', href: '/generate', icon: Sparkles },
    { title: 'History', href: '/history', icon: History },
    { title: 'Credits', href: '/credits', icon: Coins },
];

const adminNavItems: NavItem[] = [{ title: 'Spend report', href: '/admin/report', icon: BarChart3 }];

const balance = computed(() => page.props.balance?.formatted ?? '$0.00');
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('generate')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="page.props.auth.is_admin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <Link
                :href="route('credits')"
                class="mx-2 flex items-center justify-between rounded-md border border-sidebar-border px-3 py-2 text-sm hover:bg-sidebar-accent group-data-[collapsible=icon]:hidden"
            >
                <span class="text-muted-foreground">Balance</span>
                <span class="font-semibold tabular-nums">{{ balance }}</span>
            </Link>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
