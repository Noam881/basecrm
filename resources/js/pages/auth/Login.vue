<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";

defineOptions({
    layout: {
        title: "Sign in",
        description: "Enter your email address to receive a sign-in link.",
    },
});

defineProps<{
    status?: string | null;
    email?: string | null;
}>();
</script>

<template>
    <Head title="Sign in" />

    <div class="crm-stack">
        <p v-if="status" role="status" class="crm-notice-panel text-sm">
            {{ status }}
        </p>

        <Form
            action="/login"
            method="post"
            v-slot="{ errors, processing }"
            class="crm-form"
        >
            <div class="crm-field">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    autocomplete="email"
                    :default-value="email ?? ''"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="crm-form-action">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Email me a sign-in link
                </Button>
            </div>
        </Form>
    </div>
</template>
