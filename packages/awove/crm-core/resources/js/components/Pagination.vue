<script setup lang="ts">
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
            >{{ page.total }} records · Page {{ page.current_page }} of
            {{ page.last_page }}</span
        >
        <div>
            <Link v-if="page.prev_page_url" :href="page.prev_page_url"
                >Previous</Link
            ><Link v-if="page.next_page_url" :href="page.next_page_url"
                >Next</Link
            >
        </div>
    </div>
</template>
