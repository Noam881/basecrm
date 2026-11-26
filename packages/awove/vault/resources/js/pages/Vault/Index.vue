<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head } from "@inertiajs/vue3";
import VaultWorkbench from "../../components/VaultWorkbench.vue";
import type { InitialData } from "../../types";
const props = defineProps<InitialData>();
</script>
<template>
    <Head :title="t('Vault')" />
    <div class="crm-heading">
        <h1>{{ t("Vault") }}</h1>
    </div>
    <VaultWorkbench :initial="props" />
</template>
