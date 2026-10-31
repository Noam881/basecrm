<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
defineProps<{ canCampaigns: boolean; canContent: boolean }>();
</script>
<template>
    <nav class="crm-tabs" aria-label="Marketing">
        <Link href="/marketing">Overview</Link
        ><Link v-if="canCampaigns" href="/marketing/campaigns">Campaigns</Link
        ><Link v-if="canCampaigns" href="/marketing/reports">Performance</Link
        ><Link v-if="canContent" href="/marketing/content"
            >Content calendar</Link
        >
    </nav>
</template>
