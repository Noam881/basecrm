<script setup lang="ts">
import { t as tr, localeTag } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import FeedbackEditor from "../../components/FeedbackEditor.vue";
import type { Feedback, Options } from "../../types";
const props = defineProps<
    Options & {
        record: Feedback;
        history: {
            id: number;
            event: string;
            summary: string;
            occurred_at: string;
            staff: { name: string } | null;
        }[];
    }
>();
function archive() {
    if (
        confirm(
            tr("Archive this feedback? The stored record will be retained."),
        )
    )
        router.post(`/service/feedback/${props.record.id}/archive`, {
            version: props.record.version,
        });
}
</script>
<template>
    <Head :title="tr('Customer feedback')" />
    <Link class="crm-back" href="/service">{{ tr("← Customer service") }}</Link>
    <div class="crm-heading">
        <h1>{{ tr("Customer feedback") }}</h1>
        <span class="crm-badge">{{ record.rating }} / 5</span>
    </div>
    <div class="crm-flow">
        <section class="crm-card crm-flow">
            <p>
                <strong>{{
                    record.customer?.name ?? tr("Customer unavailable")
                }}</strong>
                · {{ record.received_on }}
            </p>
            <p>{{ record.comments }}</p>
            <p>
                {{ tr("Owner:") }} {{ record.owner?.name ?? tr("Unassigned") }}
            </p>
            <Link
                v-if="record.request_id"
                :href="`/service/requests/${record.request_id}`"
                >{{ tr("Related request #") }}{{ record.request_id }}</Link
            >
            <p v-if="record.action_required">
                {{
                    record.followed_up_on
                        ? tr("Follow-up completed {date}", {
                              date: record.followed_up_on,
                          })
                        : tr("Follow up {date}", {
                              date: record.follow_up_on ?? "—",
                          })
                }}
            </p>
            <p v-if="record.archived_at">
                {{ tr("Archived · retained read-only record.") }}
            </p>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>{{ tr("Edit feedback") }}</h2>
            <FeedbackEditor :key="record.version" v-bind="props" />
            <div class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    {{ tr("Archive feedback") }}
                </button>
            </div>
        </section>
        <section class="crm-card crm-flow" :aria-label="tr('Feedback history')">
            <h2>{{ tr("Feedback history") }}</h2>
            <article v-for="item in history" :key="item.id">
                <p>{{ item.summary }}</p>
                <small
                    >{{ item.staff?.name ?? tr("Former staff") }} ·
                    {{
                        new Date(item.occurred_at).toLocaleString(localeTag())
                    }}</small
                >
            </article>
        </section>
    </div>
</template>
