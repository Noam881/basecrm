<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import RequestEditor from "../../components/RequestEditor.vue";
import type { Options, ServiceRequest, Identity } from "../../types";
const props = defineProps<
    Options & {
        record: ServiceRequest;
        history: {
            id: number;
            event: string;
            kind: string | null;
            summary: string;
            occurred_at: string;
            staff: Identity | null;
            document_link: string | null;
            details: {
                resolution?: string | null;
                resolved_on?: string | null;
            } | null;
        }[];
    }
>();
const interaction = useForm({ kind: "note", occurred_at: "", summary: "" });
const timezone = (usePage().props.brand as { timezone: string }).timezone;
const localNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
interaction.occurred_at = localNow;
function addInteraction() {
    interaction
        .transform((data) => ({
            ...data,
            occurred_at: new Date(data.occurred_at)
                .toISOString()
                .replace(".000Z", "+00:00"),
        }))
        .post(`/service/requests/${props.record.id}/interactions`, {
            preserveScroll: true,
            onSuccess: () => interaction.reset("summary"),
        });
}
function archive() {
    if (confirm("Archive this request? Its history will be retained."))
        router.post(`/service/requests/${props.record.id}/archive`, {
            version: props.record.version,
        });
}
</script>
<template>
    <Head :title="record.subject" />
    <Link class="crm-back" href="/service">← Customer service</Link>
    <div class="crm-heading">
        <h1>{{ record.subject }}</h1>
        <span class="crm-badge">{{ statuses[record.status] }}</span>
    </div>
    <div class="crm-flow">
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Customer</dt>
                    <dd>
                        <Link
                            v-if="record.customer"
                            :href="`/people/${record.customer.type}/${record.customer.id}`"
                            >{{ record.customer.name }}</Link
                        ><template v-else>Customer unavailable</template>
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Owner</dt>
                    <dd>{{ record.owner?.name ?? "Unassigned" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Received</dt>
                    <dd>{{ record.received_on }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Channel</dt>
                    <dd>{{ channels[record.channel] }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Priority</dt>
                    <dd>{{ record.priority }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Due date</dt>
                    <dd>
                        {{ record.due_on ?? "Not scheduled"
                        }}<strong v-if="record.overdue"> · Overdue</strong>
                    </dd>
                </div>
            </dl>
            <p>{{ record.description }}</p>
            <p v-if="record.next_action">
                <strong>Next action:</strong> {{ record.next_action }}
            </p>
            <p v-if="record.archived_at">
                Archived · retained read-only record.
            </p>
            <template v-if="record.resolution"
                ><h2>Latest resolution</h2>
                <p>{{ record.resolution }}</p>
                <small
                    >{{ record.resolved_on }} · Earlier resolutions remain in
                    history after reopening.</small
                ></template
            ><a
                v-if="record.document_link"
                class="crm-secondary"
                :href="record.document_link"
                target="_blank"
                rel="noopener noreferrer"
                >Open supporting document</a
            >
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>Edit request</h2>
            <RequestEditor :key="record.version" v-bind="props" />
            <p class="crm-muted">
                To reopen, choose New, In progress or Waiting for customer and
                save. Existing history and resolutions are retained.
            </p>
            <div class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    Archive request
                </button>
            </div>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>Add interaction</h2>
            <form class="crm-form-grid" @submit.prevent="addInteraction">
                <label class="crm-field"
                    ><span class="crm-field-label">Interaction type</span
                    ><select v-model="interaction.kind">
                        <option value="note">Note</option>
                        <option value="call">Call</option>
                        <option value="meeting">Meeting</option>
                        <option value="email">Email</option>
                        <option value="message">Message</option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label"
                        >Date and time ({{ timezone }} display; entered in
                        browser timezone)</span
                    ><input
                        v-model="interaction.occurred_at"
                        type="datetime-local"
                        required
                    /><small
                        v-if="interaction.errors.occurred_at"
                        role="alert"
                        data-slot="input-error"
                        >{{ interaction.errors.occurred_at }}</small
                    ></label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Summary</span
                    ><textarea
                        v-model="interaction.summary"
                        required
                        rows="3"
                        maxlength="10000"
                    /><small
                        v-if="interaction.errors.summary"
                        role="alert"
                        data-slot="input-error"
                        >{{ interaction.errors.summary }}</small
                    ></label
                >
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="interaction.processing"
                    >
                        {{
                            interaction.processing
                                ? "Saving…"
                                : "Add interaction"
                        }}
                    </button>
                </div>
            </form>
        </section>
        <section class="crm-card crm-flow">
            <h2>Request history</h2>
            <article
                v-for="item in history"
                :key="item.id"
                class="crm-timeline-item"
            >
                <strong>{{
                    item.kind ?? item.event.replaceAll("_", " ")
                }}</strong>
                <p>{{ item.summary }}</p>
                <template v-if="item.details?.resolution"
                    ><p>{{ item.details.resolution }}</p>
                    <small
                        >Resolution date: {{ item.details.resolved_on }}</small
                    ></template
                >
                <a
                    v-if="item.document_link"
                    :href="item.document_link"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Open retained supporting document</a
                >
                <p class="crm-muted">
                    {{ item.staff?.name ?? "Former staff" }} ·
                    {{
                        new Intl.DateTimeFormat("en", {
                            dateStyle: "medium",
                            timeStyle: "short",
                            timeZone: timezone,
                        }).format(new Date(item.occurred_at))
                    }}
                </p>
            </article>
        </section>
    </div>
</template>
