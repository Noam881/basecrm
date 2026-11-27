<script setup lang="ts">
import { t } from "@/lib/i18n";
import type { HistoryFile } from "../history";
withDefaults(defineProps<{ files: HistoryFile[]; showEmpty?: boolean }>(), {
    showEmpty: true,
});
</script>
<template>
    <section v-if="files.length || showEmpty" class="crm-flow">
        <h3>{{ t("Financial files") }}</h3>
        <p v-if="!files.length" class="crm-muted">
            {{ t("No financial files are available for this record.") }}
        </p>
        <article v-for="file in files" :key="file.id" class="crm-note crm-flow">
            <div>
                <strong
                    ><bdi>{{ file.name }}</bdi></strong
                >
                <p class="crm-muted">
                    <bdi>{{
                        Math.max(1, Math.ceil(file.size_bytes / 1024))
                    }}</bdi>
                    {{ t("KB") }}
                    <template v-if="file.source.author.name">
                        · <bdi>{{ file.source.author.name }}</bdi>
                    </template>
                </p>
                <p v-if="file.source.created_at" class="crm-muted">
                    {{
                        t(
                            file.source.created_precision === "date"
                                ? "Original file date"
                                : "Original file time",
                        )
                    }}:
                    <bdi>{{ file.source.created_at }}</bdi>
                </p>
            </div>
            <div class="crm-form-action">
                <a class="crm-secondary" :href="file.download_url">
                    {{ t("Download financial file") }}
                </a>
            </div>
        </article>
    </section>
</template>
