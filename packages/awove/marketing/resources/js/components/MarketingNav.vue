<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
defineProps<{ canCampaigns: boolean; canContent: boolean }>();
</script>
<template>
    <nav class="crm-tabs" :aria-label="tr('Marketing')">
        <Link href="/marketing">{{ tr("Overview") }}</Link
        ><Link v-if="canCampaigns" href="/marketing/campaigns">{{
            tr("Campaigns")
        }}</Link
        ><Link v-if="canCampaigns" href="/marketing/reports">{{
            tr("Performance")
        }}</Link
        ><Link v-if="canContent" href="/marketing/content">{{
            tr("Content calendar")
        }}</Link>
    </nav>
</template>
