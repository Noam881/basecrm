<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import type { Document } from "../types";
import FormErrors from "./FormErrors.vue";
const p = defineProps<{
    assetId: number;
    documents: Document[];
    editable: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
}>();
const f = useForm({ name: "", url: "" });
function save() {
    f.post(`/assets/register/${p.assetId}/documents`, {
        preserveScroll: true,
        onSuccess: () => f.reset(),
    });
}
const archival = useForm({ version: 0 });
function archive(d: Document) {
    archival.version = d.version;
    archival.post(`/assets/documents/${d.id}/archive`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <section
        v-if="canViewDocuments || canManageDocuments"
        class="crm-card crm-flow"
    >
        <h2>{{ tr("Documents") }}</h2>
        <p v-if="canViewDocuments && !documents.length" class="crm-muted">
            {{ tr("No document links recorded.") }}
        </p>
        <article
            v-for="document in documents"
            :key="document.id"
            class="crm-note crm-flow"
        >
            <a
                v-if="canViewDocuments"
                class="crm-inline-link"
                :href="document.href"
                rel="noreferrer"
                >{{ document.name }}</a
            ><strong v-else>{{ document.name }}</strong
            ><span v-if="document.archived_at" class="crm-muted">{{
                tr("Archived")
            }}</span>
            <div
                v-if="editable && canManageDocuments && !document.archived_at"
                class="crm-form-action"
            >
                <button
                    class="crm-secondary"
                    type="button"
                    :disabled="archival.processing"
                    @click="archive(document)"
                >
                    {{ tr("Archive document") }}
                </button>
            </div>
        </article>
        <FormErrors :errors="archival.errors" />
        <details
            v-if="editable && canManageDocuments"
            class="crm-form-disclosure"
        >
            <summary>{{ tr("Add document link") }}</summary>
            <form class="crm-form-grid" @submit.prevent="save">
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Document name")
                    }}</span
                    ><input v-model="f.name" required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Document URL")
                    }}</span
                    ><input
                        v-model="f.url"
                        type="url"
                        :placeholder="tr('https://')"
                        required /></label
                ><FormErrors :errors="f.errors" />
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="f.processing">
                        {{ tr("Add document") }}
                    </button>
                </div>
            </form>
        </details>
    </section>
</template>
