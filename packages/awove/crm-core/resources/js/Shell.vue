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
    <div class="crm" :style="{ '--md-sys-color-primary': brand.color }">
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
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >space_dashboard</span
                    >Overview</Link
                ><Link
                    href="/leads"
                    :class="{ selected: page.url.startsWith('/leads') }"
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >view_list</span
                    >{{ brand.lead_label }}</Link
                ><Link
                    href="/people"
                    :class="{ selected: page.url.startsWith('/people') }"
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >contacts</span
                    >Contacts & companies</Link
                ><Link
                    href="/tasks"
                    :class="{ selected: page.url.startsWith('/tasks') }"
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >task_alt</span
                    >Follow-ups</Link
                ><Link
                    href="/reminders"
                    :class="{ selected: page.url.startsWith('/reminders') }"
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >notifications</span
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
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >settings</span
                    >Business settings</Link
                ><Link href="/settings/security"
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >security</span
                    >Account security</Link
                ><Link href="/logout" method="post" as="button"
                    ><span class="material-symbols-outlined" aria-hidden="true"
                        >logout</span
                    >Sign out</Link
                >
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
