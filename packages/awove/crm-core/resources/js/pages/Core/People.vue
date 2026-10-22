<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Person, Page } from "../../types";
import Pagination from "../../components/Pagination.vue";
const props = defineProps<{
    contacts: Page<Person>;
    companies: Page<Person>;
    q: string;
}>();
const search = ref(props.q ?? "");
const type = ref("contacts");
const form = useForm({
    name: "",
    email: "",
    phone: "",
    company_id: null as number | null,
    accept_duplicate: false,
});
</script>
<template>
    <Head title="Contacts & companies" />
    <div class="crm-heading">
        <div>
            <p class="crm-eyebrow">PEOPLE BEFORE PIPELINES</p>
            <h1>Contacts & companies</h1>
            <p>One identity. A history of possibilities.</p>
        </div>
    </div>
    <section class="crm-card">
        <h2>Add a customer</h2>
        <form
            class="crm-form-grid"
            @submit.prevent="
                form.post(`/people/${type}`, { onSuccess: () => form.reset() })
            "
        >
            <label
                >Record type<select v-model="type">
                    <option value="contacts">Contact</option>
                    <option value="companies">Company</option>
                </select></label
            ><label>Name<input v-model="form.name" required /></label
            ><label>Email<input v-model="form.email" type="email" /></label
            ><label>Phone<input v-model="form.phone" type="tel" /></label
            ><label v-if="type === 'contacts'"
                >Company<select v-model="form.company_id">
                    <option :value="null">Individual</option>
                    <option
                        v-for="c in companies.data"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.name }}
                    </option>
                </select></label
            ><label class="crm-checkbox"
                ><input v-model="form.accept_duplicate" type="checkbox" />Keep a
                separate record if a duplicate is found</label
            >
            <div class="crm-form-action">
                <button class="crm-button" :disabled="form.processing">
                    Create customer
                </button>
            </div>
        </form>
    </section>
    <form
        class="crm-search"
        @submit.prevent="router.get('/people', { q: search })"
    >
        <label
            >Find a customer<input
                v-model="search"
                placeholder="Name, email or phone" /></label
        ><button class="crm-secondary">Search</button>
    </form>
    <div class="crm-dashboard-grid">
        <section
            v-for="group in [
                { title: 'Contacts', page: contacts },
                { title: 'Companies', page: companies },
            ]"
            :key="group.title"
            class="crm-card"
        >
            <h2>{{ group.title }}</h2>
            <p v-if="!group.page.data.length" class="crm-empty">
                No records in this view.
            </p>
            <article v-for="p in group.page.data" :key="p.id" class="crm-note">
                <strong>{{ p.name }}</strong>
                <p>{{ p.email ?? "No email" }} · {{ p.phone ?? "No phone" }}</p>
            </article>
            <Pagination :page="group.page" />
        </section>
    </div>
</template>
