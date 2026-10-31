<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Person, Stage } from "../../types";
const p = defineProps<{
    settings: {
        name: string;
        timezone: string;
        lead_label: string;
    };
    statuses: Stage[];
    users: (Person & { active: boolean; roles: { name: string }[] })[];
    system: unknown;
    locks: string[];
}>();
const form = useForm({
    name: p.settings.name,
    timezone: p.settings.timezone,
    lead_label: p.settings.lead_label,
});
const user = useForm({ name: "", email: "", role: "Employee" });
const stages = ref(p.statuses.map((s) => ({ ...s })));
const targets = ref<Record<number, number | null>>({});
</script>
<template>
    <Head :title="t('Business settings')" />
    <div class="crm-heading">
        <h1>{{ t("Business settings") }}</h1>
    </div>
    <div class="crm-stack">
        <section class="crm-card">
            <h2>{{ t("Identity & preferences") }}</h2>
            <form
                class="crm-form-grid"
                @submit.prevent="form.patch('/business-settings')"
            >
                <label
                    ><span class="crm-field-label">{{
                        t("Application name")
                    }}</span
                    ><input v-model="form.name" /></label
                ><label
                    ><span class="crm-field-label">{{ t("Timezone") }}</span
                    ><input v-model="form.timezone" /></label
                ><label
                    ><span class="crm-field-label">{{
                        t("Lead terminology")
                    }}</span
                    ><input v-model="form.lead_label"
                /></label>
                <div class="crm-form-action">
                    <button class="crm-button">{{ t("Save settings") }}</button>
                </div>
            </form>
            <small
                >{{ t("Deployment locks:") }} {{ locks.join(", ")
                }}{{ t(". Modules are installed through deployment.") }}</small
            >
        </section>
        <section class="crm-card">
            <h2>{{ t("Pipeline") }}</h2>
            <p class="crm-muted">
                {{
                    t(
                        "Stable identifiers and outcome semantics stay consistent when labels change.",
                    )
                }}
            </p>
            <form
                v-for="s in stages"
                :key="s.id"
                class="crm-status-form"
                @submit.prevent="
                    router.post('/statuses', {
                        id: s.id,
                        name: s.name,
                        position: s.position,
                    })
                "
            >
                <label
                    ><span class="crm-field-label">{{ s.id }}</span
                    ><input v-model="s.name" required /></label
                ><label
                    ><span class="crm-field-label">{{ t("Order") }}</span
                    ><input
                        v-model="s.position"
                        type="number"
                        min="0"
                        max="100" /></label
                ><span class="crm-badge">{{
                    t(s.kind.charAt(0).toUpperCase() + s.kind.slice(1))
                }}</span
                ><button class="crm-secondary">{{ t("Save stage") }}</button>
            </form>
        </section>
        <section v-if="users.length" class="crm-card">
            <h2>{{ t("Team") }}</h2>
            <form
                class="crm-form-grid"
                @submit.prevent="
                    user.post('/users', { onSuccess: () => user.reset() })
                "
            >
                <label
                    ><span class="crm-field-label">{{ t("Name") }}</span
                    ><input v-model="user.name" required /></label
                ><label
                    ><span class="crm-field-label">{{ t("Email") }}</span
                    ><input v-model="user.email" type="email" required /></label
                ><label
                    ><span class="crm-field-label">{{ t("Preset") }}</span
                    ><select v-model="user.role">
                        <option value="Employee">{{ t("Employee") }}</option>
                        <option value="Administrator">
                            {{ t("Administrator") }}
                        </option>
                    </select></label
                >
                <div class="crm-form-action">
                    <button class="crm-button">{{ t("Invite user") }}</button>
                </div>
            </form>
            <article v-for="u in users" :key="u.id" class="crm-note">
                <strong
                    >{{ u.name }} ·
                    {{ u.roles.map((r) => t(r.name)).join(", ") }}</strong
                >
                <p>
                    {{ u.email }} · {{ u.active ? t("Active") : t("Inactive") }}
                </p>
                <div class="crm-inline">
                    <label v-if="u.active"
                        ><span class="crm-field-label">{{
                            t("Reassign leads to")
                        }}</span
                        ><select v-model="targets[u.id]">
                            <option :value="null">
                                {{ t("Unassigned queue") }}
                            </option>
                            <option
                                dir="auto"
                                v-for="o in users.filter(
                                    (o) => o.active && o.id !== u.id,
                                )"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }}
                            </option>
                        </select></label
                    ><button
                        class="crm-secondary"
                        @click="
                            router.patch(`/users/${u.id}`, {
                                active: !u.active,
                                reassign_to: targets[u.id] ?? null,
                            })
                        "
                    >
                        {{ u.active ? t("Deactivate") : t("Reactivate") }}
                    </button>
                </div>
            </article>
        </section>
        <section class="crm-card">
            <h2>{{ t("System information") }}</h2>
            <pre class="crm-system">{{ JSON.stringify(system, null, 2) }}</pre>
        </section>
    </div>
</template>
