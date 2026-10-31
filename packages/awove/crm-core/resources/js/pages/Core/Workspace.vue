<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link } from "@inertiajs/vue3";
import ModuleWidgets, {
    type DashboardWidget,
} from "../../components/ModuleWidgets.vue";
defineProps<{
    moduleNavigation: { label: string; href: string }[];
    moduleWidgets: DashboardWidget[];
}>();
</script>
<template>
    <Head :title="t('Overview')" />
    <h1>{{ t("Overview") }}</h1>
    <ModuleWidgets :widgets="moduleWidgets" />
    <div class="crm-card crm-flow">
        <h2>{{ t("Your workspace") }}</h2>
        <p v-if="!moduleNavigation.length">
            {{
                t(
                    "No business modules are available for your account. Ask your administrator for access.",
                )
            }}
        </p>
        <Link
            v-for="item in moduleNavigation"
            :key="item.href"
            :href="item.href"
            class="crm-button crm-secondary"
            >{{ item.label }}</Link
        >
    </div>
</template>
