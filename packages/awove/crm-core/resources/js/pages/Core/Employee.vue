<script setup lang="ts">
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import EmployeeReferenceFields from "../../components/EmployeeReferenceFields.vue";
import HistoryList from "../../components/HistoryList.vue";
interface Person {
    id: number;
    name: string;
    profile_url?: string;
}
interface EmployeeRecord {
    id: number;
    name: string;
    active: boolean;
    version: number;
    contact_id: number | null;
    user_id: number | null;
    contact: Person | null;
    account: Person | null;
    has_hidden_contact: boolean;
    has_hidden_account: boolean;
}
const props = defineProps<{
    record: EmployeeRecord;
    canManage: boolean;
    contacts: Person[];
    accounts: Person[];
    history: {
        id: number;
        event: string;
        version: number;
        changed_fields: string[];
        actor: Person | null;
        created_at: string;
    }[];
}>();
const form = useForm({
    name: props.record.name,
    active: props.record.active,
    contact_id: props.record.contact_id,
    user_id: props.record.user_id,
    contact_action: "keep",
    user_action: "keep",
    version: props.record.version,
});
const warning = ref("");
const fieldLabels: Record<string, string> = {
    name: "Name",
    active: "Active status",
    contact_id: "Contact link",
    user_id: "Account link",
};
function save() {
    form.patch(`/people/employees/${props.record.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.version = props.record.version;
            form.contact_action = "keep";
            form.user_action = "keep";
        },
        onHttpException: (response: { status: number }) => {
            if ([403, 409].includes(response.status)) {
                warning.value =
                    "This reference or your access changed. Reload before saving.";
                return false;
            }
        },
    });
}
function toggle() {
    router.patch(
        `/people/employees/${props.record.id}/active`,
        { version: props.record.version, active: !props.record.active },
        {
            preserveScroll: true,
            onSuccess: () => {
                form.active = props.record.active;
                form.version = props.record.version;
            },
        },
    );
}
</script>
<template>
    <Head :title="record.name" /><Link class="crm-back" href="/people/employees"
        >← Employee references</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <span class="crm-badge">{{
            record.active ? "Active" : "Inactive"
        }}</span>
    </div>
    <div class="crm-flow">
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Employee reference ID</dt>
                    <dd>{{ record.id }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Linked contact</dt>
                    <dd>
                        <Link
                            v-if="record.contact?.profile_url"
                            :href="record.contact.profile_url"
                            >{{ record.contact.name }}</Link
                        ><template v-else>{{
                            record.has_hidden_contact
                                ? "Unavailable"
                                : "Not linked"
                        }}</template>
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Linked account</dt>
                    <dd>
                        {{
                            record.account?.name ??
                            (record.has_hidden_account
                                ? "Unavailable"
                                : "Not linked")
                        }}
                    </dd>
                </div>
            </dl>
            <p v-if="!record.active" class="crm-muted">
                Inactive references remain available to permitted historical and
                final-period workflows.
            </p>
        </section>
        <section v-if="canManage" class="crm-card crm-flow">
            <h2>Edit employee reference</h2>
            <form class="crm-form-grid" @submit.prevent="save">
                <EmployeeReferenceFields
                    :model-value="form"
                    :contacts="contacts"
                    :accounts="accounts"
                    :editing="true"
                    :hidden-contact="record.has_hidden_contact"
                    :hidden-account="record.has_hidden_account"
                    :current-contact="record.contact?.name"
                    :current-account="record.account?.name"
                    :errors="form.errors"
                />
                <div
                    v-if="form.hasErrors || warning"
                    class="crm-error crm-form-span"
                    role="alert"
                >
                    <p v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </p>
                    <p>{{ warning }}</p>
                </div>
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="form.processing || !!warning"
                    >
                        {{
                            form.processing
                                ? "Saving…"
                                : "Save employee reference"
                        }}</button
                    ><button
                        type="button"
                        class="crm-secondary"
                        @click="toggle"
                    >
                        {{
                            record.active
                                ? "Deactivate reference"
                                : "Reactivate reference"
                        }}
                    </button>
                </div>
            </form>
        </section>
        <HistoryList
            :history="history"
            title="Reference history"
            :event-labels="{
                'employee-reference.created': 'Reference created',
                'employee-reference.updated': 'Reference updated',
            }"
            :field-labels="fieldLabels"
        />
    </div>
</template>
