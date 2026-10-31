<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import RequestFields from "../../../../crm-core/resources/js/components/RequestFields.vue";
import type { Options, ServiceRequest } from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options & { record?: ServiceRequest }>();
const form = useForm({
    subject: props.record?.subject ?? "",
    description: props.record?.description ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    contact_id: props.record?.contact_id ?? null,
    company_id: props.record?.company_id ?? null,
    received_on: props.record?.received_on ?? props.today,
    channel: props.record?.channel ?? "email",
    status: props.record?.status ?? "new",
    priority: props.record?.priority ?? "normal",
    next_action: props.record?.next_action ?? "",
    due_on: props.record?.due_on ?? "",
    resolution: props.record?.resolution ?? "",
    resolved_on: props.record?.resolved_on ?? "",
    document_url: props.record?.document_url ?? "",
    version: props.record?.version ?? 1,
});
function save() {
    if (props.record)
        form.patch(`/service/requests/${props.record.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.version = props.record!.version;
            },
        });
    else form.post("/service/requests");
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <p v-if="form.hasErrors" class="crm-form-span" role="alert">
            {{ tr("Please check the highlighted fields.") }}
        </p>
        <RequestFields
            :model-value="form"
            :owners="owners"
            :can-assign="canAssign"
            :errors="form.errors"
        />
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Contact") }}</span
            ><select v-model="form.contact_id">
                <option :value="null">{{ tr("Choose contact") }}</option>
                <option
                    v-for="contact in contacts"
                    :key="contact.id"
                    :value="contact.id"
                >
                    {{ contact.name }}
                </option></select
            ><small
                v-if="form.errors.contact_id"
                data-slot="input-error"
                role="alert"
                >{{ form.errors.contact_id }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Company") }}</span
            ><select v-model="form.company_id">
                <option :value="null">{{ tr("Choose company") }}</option>
                <option
                    v-for="company in companies"
                    :key="company.id"
                    :value="company.id"
                >
                    {{ company.name }}
                </option></select
            ><small
                v-if="form.errors.company_id"
                data-slot="input-error"
                role="alert"
                >{{ form.errors.company_id }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Date received") }}</span
            ><input v-model="form.received_on" type="date" required /><small
                v-if="form.errors.received_on"
                data-slot="input-error"
                role="alert"
                >{{ form.errors.received_on }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Channel") }}</span
            ><select v-model="form.channel">
                <option
                    v-for="(label, key) in channels"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Status") }}</span
            ><select v-model="form.status">
                <option
                    v-for="(label, key) in statuses"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select></label
        >
        <label
            v-if="['resolved', 'closed'].includes(form.status)"
            class="crm-field"
            ><span class="crm-field-label">{{ tr("Resolution date") }}</span
            ><input v-model="form.resolved_on" type="date" /><small
                v-if="form.errors.resolved_on"
                data-slot="input-error"
                role="alert"
                >{{ form.errors.resolved_on }}</small
            ></label
        >
        <label
            v-if="['resolved', 'closed'].includes(form.status)"
            class="crm-field crm-form-span"
            ><span class="crm-field-label">{{ tr("Resolution") }}</span
            ><textarea
                v-model="form.resolution"
                rows="3"
                required
                maxlength="20000"
            /><small
                v-if="form.errors.resolution"
                data-slot="input-error"
                role="alert"
                >{{ form.errors.resolution }}</small
            ></label
        >
        <label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{
                tr("Supporting document link")
            }}</span
            ><input
                v-model="form.document_url"
                type="url"
                :placeholder="tr('https://')"
                maxlength="2000"
            /><small>{{
                tr(
                    "Use an HTTPS link. Keep sensitive documents protected at the document provider.",
                )
            }}</small
            ><small
                v-if="form.errors.document_url"
                data-slot="input-error"
                role="alert"
                >{{ form.errors.document_url }}</small
            ></label
        >
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save request")
                          : tr("Create request")
                }}
            </button>
        </div>
    </form>
</template>
