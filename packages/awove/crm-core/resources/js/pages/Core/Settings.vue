<script setup lang="ts">
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Person, Stage } from "../../types";
const p = defineProps<{
    settings: {
        name: string;
        color: string;
        timezone: string;
        lead_label: string;
    };
    statuses: Stage[];
    users: (Person & { active: boolean; roles: { name: string }[] })[];
    system: unknown;
    locks: string[];
}>();
const form = useForm({ ...p.settings });
const user = useForm({ name: "", email: "", role: "Employee" });
const stages = ref(p.statuses.map((s) => ({ ...s })));
const targets = ref<Record<number, number | null>>({});
</script>
<template>
    <Head title="Business settings" />
    <div class="crm-heading">
        <div>
            <p class="crm-eyebrow">MAKE IT YOURS</p>
            <h1>Business settings</h1>
            <p>Configure this installation without changing the shared core.</p>
        </div>
    </div>
    <div class="crm-stack">
        <section class="crm-card">
            <h2>Identity & preferences</h2>
            <form
                class="crm-form-grid"
                @submit.prevent="
                    form
                        .transform((d) => ({
                            name: d.name,
                            color: d.color,
                            timezone: d.timezone,
                            lead_label: d.lead_label,
                        }))
                        .patch('/business-settings')
                "
            >
                <label>Application name<input v-model="form.name" /></label
                ><label
                    >Brand color<input
                        v-model="form.color"
                        type="color" /></label
                ><label>Timezone<input v-model="form.timezone" /></label
                ><label
                    >Lead terminology<input v-model="form.lead_label"
                /></label>
                <div class="crm-form-action">
                    <button class="crm-button">Save settings</button>
                </div>
            </form>
            <small
                >Deployment locks: {{ locks.join(", ") }}. Modules are installed
                through deployment.</small
            >
        </section>
        <section class="crm-card">
            <h2>Pipeline</h2>
            <p class="crm-muted">
                Stable identifiers and outcome semantics stay consistent when
                labels change.
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
                <label>{{ s.id }}<input v-model="s.name" required /></label
                ><label
                    >Order<input
                        v-model="s.position"
                        type="number"
                        min="0"
                        max="100" /></label
                ><span class="crm-badge">{{ s.kind }}</span
                ><button class="crm-secondary">Save stage</button>
            </form>
        </section>
        <section v-if="users.length" class="crm-card">
            <h2>Team</h2>
            <form
                class="crm-form-grid"
                @submit.prevent="
                    user.post('/users', { onSuccess: () => user.reset() })
                "
            >
                <label>Name<input v-model="user.name" required /></label
                ><label
                    >Email<input
                        v-model="user.email"
                        type="email"
                        required /></label
                ><label
                    >Preset<select v-model="user.role">
                        <option>Employee</option>
                        <option>Administrator</option>
                    </select></label
                >
                <div class="crm-form-action">
                    <button class="crm-button">Invite user</button>
                </div>
            </form>
            <article v-for="u in users" :key="u.id" class="crm-note">
                <strong
                    >{{ u.name }} ·
                    {{ u.roles.map((r) => r.name).join(", ") }}</strong
                >
                <p>{{ u.email }} · {{ u.active ? "Active" : "Inactive" }}</p>
                <div class="crm-inline">
                    <label v-if="u.active"
                        >Reassign leads to<select v-model="targets[u.id]">
                            <option :value="null">Unassigned queue</option>
                            <option
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
                        {{ u.active ? "Deactivate" : "Reactivate" }}
                    </button>
                </div>
            </article>
        </section>
        <section class="crm-card">
            <h2>System information</h2>
            <pre class="crm-system">{{ JSON.stringify(system, null, 2) }}</pre>
        </section>
    </div>
</template>
