<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import type { Feedback, Options } from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options & { record?: Feedback }>();
const form = useForm({
    contact_id: props.record?.contact_id ?? null,
    company_id: props.record?.company_id ?? null,
    request_id: props.record?.request_id ?? null,
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    received_on: props.record?.received_on ?? props.today,
    rating: props.record?.rating ?? 3,
    comments: props.record?.comments ?? "",
    action_required: props.record?.action_required ?? false,
    follow_up_on: props.record?.follow_up_on ?? "",
    followed_up_on: props.record?.followed_up_on ?? "",
    version: props.record?.version ?? 1,
});
function selectRequest() {
    const related = props.relatedRequests.find((r) => r.id === form.request_id);
    if (related) {
        form.contact_id = related.contact_id;
        form.company_id = related.company_id;
    }
}
function save() {
    if (props.record)
        form.patch(`/service/feedback/${props.record.id}`, {
            onSuccess: () => {
                form.version = props.record!.version;
            },
        });
    else form.post("/service/feedback");
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <p class="crm-form-span">
            {{
                tr(
                    "Rating scale: 1 = Very dissatisfied, 2 = Dissatisfied, 3 = Neutral, 4 = Satisfied, 5 = Very satisfied.",
                )
            }}
        </p>
        <label class="crm-field"
            ><span class="crm-field-label">{{
                tr("Related request (optional)")
            }}</span
            ><select v-model="form.request_id" @change="selectRequest">
                <option :value="null">{{ tr("No request") }}</option>
                <option
                    v-for="request in relatedRequests"
                    :key="request.id"
                    :value="request.id"
                >
                    #{{ request.id }} · {{ request.subject }}
                </option></select
            ><small
                v-if="form.errors.request_id"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.request_id }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Date received") }}</span
            ><input v-model="form.received_on" type="date" required /><small
                v-if="form.errors.received_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.received_on }}</small
            ></label
        >
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
                role="alert"
                data-slot="input-error"
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
                role="alert"
                data-slot="input-error"
                >{{ form.errors.company_id }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Rating (1–5)") }}</span
            ><select v-model="form.rating">
                <option v-for="rating in 5" :key="rating" :value="rating">
                    {{ rating }}
                </option></select
            ><small
                v-if="form.errors.rating"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.rating }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Owner") }}</span
            ><select v-model="form.owner_id" :disabled="!canAssign">
                <option :value="null">{{ tr("Unassigned") }}</option>
                <option
                    v-for="owner in owners"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option>
            </select></label
        >
        <label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{ tr("Comments") }}</span
            ><textarea
                v-model="form.comments"
                required
                rows="4"
                maxlength="20000"
            /><small
                v-if="form.errors.comments"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.comments }}</small
            ></label
        >
        <label class="crm-field crm-form-span"
            ><span
                ><input v-model="form.action_required" type="checkbox" />
                {{ tr("Follow-up action needed") }}</span
            ></label
        >
        <template v-if="form.action_required"
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Follow-up date") }}</span
                ><input
                    v-model="form.follow_up_on"
                    type="date"
                    required
                /><small
                    v-if="form.errors.follow_up_on"
                    role="alert"
                    data-slot="input-error"
                    >{{ form.errors.follow_up_on }}</small
                ></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Follow-up completed on")
                }}</span
                ><input v-model="form.followed_up_on" type="date" /></label
        ></template>
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save feedback")
                          : tr("Add feedback")
                }}
            </button>
        </div>
    </form>
</template>
