<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { router, useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { ProgramRecord } from "./types";
import Errors from "./Errors.vue";
const p = defineProps<{
    program: ProgramRecord;
    documents: {
        id: number;
        version: number;
        name: string;
        href: string;
        archived_at: string | null;
    }[];
    canManageDocuments: boolean;
}>();
const f = useForm({ program_version: p.program.version, name: "", url: "" });
const stale = computed(() => f.program_version !== p.program.version);
function sync() {
    f.defaults({ program_version: p.program.version, name: "", url: "" });
    f.reset();
    f.clearErrors();
}
watch(
    () => p.program.version,
    () => {
        if (!f.isDirty) sync();
    },
);
function save() {
    if (!stale.value)
        f.post(`/nonprofit/programs/programs/${p.program.id}/documents`, {
            preserveScroll: true,
            onSuccess: sync,
        });
}
function archive(id: number, version: number) {
    router.post(
        `/nonprofit/programs/documents/${id}/archive`,
        { version, program_version: p.program.version },
        { preserveScroll: true },
    );
}
</script>
<template>
    <section class="crm-card crm-flow">
        <h2>{{ tr("Program documents") }}</h2>
        <p v-if="!documents.length" class="crm-muted">
            {{ tr("No documents linked.") }}
        </p>
        <article
            v-for="document in documents"
            :key="document.id"
            class="crm-note crm-flow"
        >
            <a
                v-if="!document.archived_at && !program.archived_at"
                :href="document.href"
                target="_blank"
                rel="noopener noreferrer"
                >{{ document.name }}</a
            ><span v-else>{{ document.name }} {{ tr("· Archived") }}</span>
            <div
                v-if="
                    canManageDocuments &&
                    program.editable &&
                    !document.archived_at
                "
                class="crm-form-action"
            >
                <button
                    type="button"
                    class="crm-secondary"
                    @click="archive(document.id, document.version)"
                >
                    {{ tr("Archive document") }}
                </button>
            </div>
        </article>
        <details
            v-if="canManageDocuments && program.editable"
            class="crm-form-disclosure"
        >
            <summary>{{ tr("Add document link") }}</summary>
            <form class="crm-form" @submit.prevent="save">
                <div class="crm-form-grid">
                    <label class="crm-field"
                        ><span class="crm-field-label">{{
                            tr("Document name")
                        }}</span
                        ><input
                            v-model="f.name"
                            required
                            maxlength="180" /></label
                    ><label class="crm-field"
                        ><span class="crm-field-label">{{
                            tr("Document URL")
                        }}</span
                        ><input
                            v-model="f.url"
                            required
                            type="url"
                            maxlength="2048"
                    /></label>
                </div>
                <p v-if="stale" class="crm-notice">
                    {{
                        tr(
                            "The program changed. Reload current details before saving.",
                        )
                    }}
                </p>
                <Errors :errors="f.errors" />
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="f.processing || stale"
                    >
                        {{ tr("Add document") }}</button
                    ><button
                        v-if="stale"
                        type="button"
                        class="crm-secondary"
                        @click="sync"
                    >
                        {{ tr("Reload current details") }}
                    </button>
                </div>
            </form>
        </details>
    </section>
</template>
