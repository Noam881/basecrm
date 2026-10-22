<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
const page = usePage();
const brand = computed(
    () =>
        page.props.brand as {
            name: string;
            color: string;
            logo: string;
            lead_label: string;
        },
);
const capabilities = computed(() => page.props.capabilities as string[]);
const navigation = computed(
    () => page.props.moduleNavigation as { label: string; href: string }[],
);
const userName = computed(
    () => (page.props.auth as { user: { name: string } }).user.name,
);
const errors = computed(() => Object.values(page.props.errors ?? {}));
</script>
<template>
    <div class="crm" :style="{ '--brand': brand.color }">
        <aside class="crm-sidebar">
            <Link href="/dashboard" class="crm-brand"
                ><img :src="brand.logo" alt="" width="36" height="36" /><span>{{
                    brand.name
                }}</span></Link
            >
            <p class="crm-caption">WORKSPACE</p>
            <nav aria-label="Main navigation">
                <Link
                    href="/dashboard"
                    :class="{ selected: page.url.startsWith('/dashboard') }"
                    >Overview</Link
                ><Link
                    href="/leads"
                    :class="{ selected: page.url.startsWith('/leads') }"
                    >{{ brand.lead_label }}</Link
                ><Link
                    href="/people"
                    :class="{ selected: page.url.startsWith('/people') }"
                    >Contacts & companies</Link
                ><Link
                    href="/tasks"
                    :class="{ selected: page.url.startsWith('/tasks') }"
                    >Follow-ups</Link
                ><Link
                    href="/reminders"
                    :class="{ selected: page.url.startsWith('/reminders') }"
                    >Reminders</Link
                ><Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    >{{ item.label }}</Link
                >
            </nav>
            <div class="crm-sidebar-bottom">
                <Link
                    v-if="capabilities.includes('settings.manage')"
                    href="/business-settings"
                    >Business settings</Link
                ><Link href="/settings/security">Account security</Link
                ><Link href="/logout" method="post" as="button">Sign out</Link>
            </div>
        </aside>
        <main class="crm-main">
            <header class="crm-topbar">
                <span>YOUR RELATIONSHIPS, IN FOCUS</span
                ><span>{{ userName }}</span>
            </header>
            <div v-if="errors.length" class="crm-error" role="alert">
                <p v-for="error in errors" :key="String(error)">{{ error }}</p>
            </div>
            <div class="crm-content"><slot /></div>
        </main>
    </div>
</template>
