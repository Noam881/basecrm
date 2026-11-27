<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head } from "@inertiajs/vue3";
import VaultWorkbench from "../../components/VaultWorkbench.vue";
import VaultMondayImport from "../../components/VaultMondayImport.vue";
import { ref } from "vue";
import type { InitialData } from "../../types";
const props = defineProps<InitialData>();
const workbench = ref<{ refreshMetadata: () => Promise<void> } | null>(null);
</script>
<template>
    <Head :title="t('Vault')" />
    <div class="crm-heading">
        <h1>{{ t("Vault") }}</h1>
    </div>
    <div class="crm-flow">
        <VaultMondayImport
            v-if="props.monday_import"
            @imported="workbench?.refreshMetadata()"
        />
        <VaultWorkbench ref="workbench" :initial="props" />
    </div>
</template>
