<script setup lang="ts">
import { onMounted, ref, computed } from "vue";
import { usePage } from "@inertiajs/vue3";
const page = usePage();
const p = defineProps<{ endpoint: string; editable: boolean }>();
const canEdit = computed(
    () =>
        p.editable &&
        (page.props.capabilities as string[]).includes("service-details.edit"),
);
const data = ref({ service_reference: "", requested_service: "" });
const error = ref("");
const saved = ref(false);
const loading = ref(true);
onMounted(async () => {
    try {
        const r = await fetch(p.endpoint, {
            headers: { Accept: "application/json" },
        });
        if (!r.ok) throw new Error("Service details could not be loaded.");
        data.value = await r.json();
    } catch (e) {
        error.value = String(e);
    } finally {
        loading.value = false;
    }
});
async function save() {
    error.value = "";
    saved.value = false;
    try {
        const token = decodeURIComponent(
            document.cookie
                .split("; ")
                .find((c) => c.startsWith("XSRF-TOKEN="))
                ?.split("=")[1] ?? "",
        );
        const r = await fetch(p.endpoint, {
            method: "PUT",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": token,
            },
            body: JSON.stringify(data.value),
        });
        if (!r.ok) {
            const response = await r.json();
            throw new Error(
                response.message ?? "Unable to save service details.",
            );
        }
        saved.value = true;
    } catch (e) {
        error.value = String(e);
    }
}
</script>
<template>
    <p v-if="loading">Loading service details…</p>
    <form v-else class="crm-form" @submit.prevent="save">
        <label
            ><span class="crm-field-label">Service reference</span
            ><input
                v-model="data.service_reference"
                :disabled="!canEdit" /></label
        ><label
            ><span class="crm-field-label">Requested service</span
            ><textarea v-model="data.requested_service" :disabled="!canEdit" />
        </label>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="saved" role="status">Service details saved.</p>
        <button v-if="canEdit" class="crm-button">Save service details</button>
    </form>
</template>
