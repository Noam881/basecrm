<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
defineProps<{
    page: {
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
}>();
</script>
<template>
    <div class="crm-pagination">
        <span
            >{{ page.total }} {{ t("records · Page") }} {{ page.current_page }}
            {{ t("of") }} {{ page.last_page }}</span
        >
        <div>
            <Link v-if="page.prev_page_url" :href="page.prev_page_url">{{
                t("Previous")
            }}</Link
            ><Link v-if="page.next_page_url" :href="page.next_page_url">{{
                t("Next")
            }}</Link>
        </div>
    </div>
</template>
