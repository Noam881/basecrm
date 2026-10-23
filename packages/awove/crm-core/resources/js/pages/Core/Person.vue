<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import {
    contactFormData,
    date,
    type ContactOptions,
    type Person,
} from "../../types";
import ContactFields from "../../components/ContactFields.vue";
const props = defineProps<{
    identity: Person;
    type: "contacts" | "companies";
    editable: boolean;
    companyOptions: Person[];
    contactOptions: ContactOptions;
    returnTo: string;
    saveUrl: string;
    noteUrl?: string | null;
}>();
const page = usePage();
const zone = (page.props.brand as { timezone: string }).timezone;
const editing = ref(
    props.editable &&
        (props.type === "companies" || props.returnTo.startsWith("/leads/")),
);
const form = useForm({
    ...contactFormData(props.identity),
    accept_duplicate: false,
});
const note = useForm({ body: "" });
function optionLabel(category: keyof ContactOptions, value?: string | null) {
    return (
        props.contactOptions[category].find((option) => option.value === value)
            ?.label ?? "Not specified"
    );
}
const contactTimes = computed(
    () =>
        props.contactOptions.times
            .filter((option) =>
                props.identity.preferred_contact_times?.includes(option.value),
            )
            .map((option) => option.label)
            .join(", ") || "Not specified",
);
const contactDays = computed(
    () =>
        props.contactOptions.days
            .filter((option) =>
                props.identity.preferred_contact_days?.includes(option.value),
            )
            .map((option) => option.label)
            .join(", ") || "Any day",
);
function save() {
    form.transform((data) =>
        props.type === "contacts"
            ? data
            : {
                  name: data.name,
                  email: data.email,
                  phone: data.phone,
                  accept_duplicate: data.accept_duplicate,
              },
    ).patch(props.saveUrl, {
        onSuccess: (savedPage) => {
            const savedIdentity = savedPage.props.identity as
                Person | undefined;
            if (!savedIdentity) return;
            form.defaults({
                ...contactFormData(savedIdentity),
                accept_duplicate: false,
            });
            form.reset();
            editing.value = false;
        },
    });
}
function cancelEdit() {
    form.reset();
    form.clearErrors();
    editing.value = false;
}
function addNote() {
    if (!props.noteUrl) return;
    note.post(props.noteUrl, {
        preserveScroll: true,
        onSuccess: () => note.reset(),
    });
}
</script>
<template>
    <Head :title="identity.name" />
    <Link :href="returnTo" class="crm-back">← Back</Link>
    <div class="crm-heading">
        <h1>{{ identity.name }}</h1>
    </div>
    <div :class="type === 'contacts' ? 'crm-contact-sections' : 'crm-stack'">
        <section class="crm-card crm-flow">
            <div class="crm-section-title">
                <h2>
                    {{
                        type === "contacts"
                            ? "Contact details"
                            : "Company details"
                    }}
                </h2>
                <button
                    v-if="editable && !editing"
                    type="button"
                    class="crm-secondary"
                    @click="editing = true"
                >
                    {{
                        type === "contacts"
                            ? "Edit contact details"
                            : "Edit company details"
                    }}
                </button>
            </div>
            <p v-if="!editable" class="crm-muted">
                Your account has view access to this record.
            </p>
            <template v-if="!editing">
                <dl class="crm-detail-grid">
                    <div class="crm-detail-item">
                        <dt>Name</dt>
                        <dd>{{ identity.name }}</dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>Email</dt>
                        <dd>
                            <a
                                v-if="identity.email"
                                :href="`mailto:${identity.email}`"
                                >{{ identity.email }}</a
                            ><template v-else>Not provided</template>
                        </dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>Phone</dt>
                        <dd>
                            <a
                                v-if="identity.phone"
                                :href="`tel:${identity.phone}`"
                                >{{ identity.phone }}</a
                            ><template v-else>Not provided</template>
                        </dd>
                    </div>
                    <template v-if="type === 'contacts'">
                        <div class="crm-detail-item">
                            <dt>Company</dt>
                            <dd>
                                {{ identity.company?.name ?? "Individual" }}
                            </dd>
                        </div>
                        <div class="crm-detail-item">
                            <dt>Secondary phone</dt>
                            <dd>
                                <a
                                    v-if="identity.secondary_phone"
                                    :href="`tel:${identity.secondary_phone}`"
                                    >{{ identity.secondary_phone }}</a
                                ><template v-else>Not provided</template
                                ><span
                                    v-if="
                                        identity.secondary_phone &&
                                        identity.secondary_phone_label
                                    "
                                >
                                    ·
                                    {{
                                        optionLabel(
                                            "phone_labels",
                                            identity.secondary_phone_label,
                                        )
                                    }}</span
                                >
                            </dd>
                        </div>
                        <div class="crm-detail-item">
                            <dt>Preferred contact method</dt>
                            <dd>
                                {{
                                    optionLabel(
                                        "methods",
                                        identity.preferred_contact_method,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="crm-detail-item">
                            <dt>Best contact time</dt>
                            <dd>{{ contactTimes }}</dd>
                        </div>
                        <div class="crm-detail-item">
                            <dt>Preferred contact days</dt>
                            <dd>{{ contactDays }}</dd>
                        </div>
                        <div class="crm-detail-item crm-form-span">
                            <dt>Address</dt>
                            <dd>{{ identity.address || "Not provided" }}</dd>
                        </div>
                        <div class="crm-detail-item">
                            <dt>City</dt>
                            <dd>{{ identity.city || "Not provided" }}</dd>
                        </div>
                        <div class="crm-detail-item">
                            <dt>Country</dt>
                            <dd>{{ identity.country || "Not provided" }}</dd>
                        </div>
                        <div class="crm-detail-item crm-form-span">
                            <dt>How did they hear about us?</dt>
                            <dd>
                                {{ optionLabel("sources", identity.source) }}
                            </dd>
                        </div>
                    </template>
                </dl>
            </template>
            <form v-else class="crm-form-grid" @submit.prevent="save">
                <ContactFields
                    v-if="type === 'contacts'"
                    :form="form"
                    :options="contactOptions"
                    :companies="companyOptions"
                    :errors="form.errors"
                />
                <template v-else>
                    <label class="crm-field"
                        ><span class="crm-field-label">Name</span
                        ><input
                            v-model="form.name"
                            required
                            maxlength="120"
                            :aria-invalid="!!form.errors.name"
                        /><small
                            v-if="form.errors.name"
                            data-slot="input-error"
                            role="alert"
                            >{{ form.errors.name }}</small
                        ></label
                    >
                    <label class="crm-field"
                        ><span class="crm-field-label">Email</span
                        ><input
                            v-model="form.email"
                            type="email"
                            maxlength="160"
                            :aria-invalid="!!form.errors.email"
                        /><small
                            v-if="form.errors.email"
                            data-slot="input-error"
                            role="alert"
                            >{{ form.errors.email }}</small
                        ></label
                    >
                    <label class="crm-field"
                        ><span class="crm-field-label">Phone</span
                        ><input
                            v-model="form.phone"
                            type="tel"
                            maxlength="40"
                            :aria-invalid="!!form.errors.phone"
                        /><small
                            v-if="form.errors.phone"
                            data-slot="input-error"
                            role="alert"
                            >{{ form.errors.phone }}</small
                        ></label
                    >
                </template>
                <label class="crm-checkbox crm-form-span"
                    ><input
                        v-model="form.accept_duplicate"
                        type="checkbox"
                    />Keep a separate record if a duplicate is found</label
                >
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="form.processing">
                        Save changes
                    </button>
                    <Link
                        v-if="
                            type === 'companies' ||
                            returnTo.startsWith('/leads/')
                        "
                        :href="returnTo"
                        class="crm-secondary"
                        >Cancel</Link
                    >
                    <button
                        v-else
                        type="button"
                        class="crm-secondary"
                        @click="cancelEdit"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>
        <section
            v-if="type === 'contacts'"
            class="crm-card crm-flow"
            aria-labelledby="contact-notes-title"
        >
            <h2 id="contact-notes-title">Notes</h2>
            <form
                v-if="editable && noteUrl"
                class="crm-form"
                @submit.prevent="addNote"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">Add a note</span
                    ><textarea
                        v-model="note.body"
                        rows="4"
                        required
                        maxlength="10000"
                        :aria-invalid="!!note.errors.body"
                    /><small
                        v-if="note.errors.body"
                        data-slot="input-error"
                        role="alert"
                        >{{ note.errors.body }}</small
                    ></label
                >
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="note.processing || !note.body.trim()"
                    >
                        Add note
                    </button>
                </div>
                <p
                    v-if="note.recentlySuccessful"
                    class="crm-notice"
                    role="status"
                >
                    Note added.
                </p>
            </form>
            <div v-if="identity.notes?.length" class="crm-note-history">
                <article
                    v-for="entry in identity.notes"
                    :key="entry.id"
                    class="crm-note"
                >
                    <p>{{ entry.body }}</p>
                    <small
                        >{{ entry.author?.name ?? "Former team member" }} ·
                        {{ date(entry.created_at, zone) }}</small
                    >
                </article>
            </div>
            <p v-else class="crm-muted">No notes yet.</p>
        </section>
    </div>
</template>
