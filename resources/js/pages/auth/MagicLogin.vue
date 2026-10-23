<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import InputError from "@/components/InputError.vue";
import TextLink from "@/components/TextLink.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { login } from "@/routes";

defineOptions({
    layout: {
        title: "Sign in with email",
        description:
            "Enter your email address to get a sign-in link. No password needed.",
    },
});

defineProps<{
    status?: string;
    email?: string | null;
}>();
</script>

<template>
    <Head title="Sign in with email" />

    <div class="crm-stack">
        <p v-if="status" role="status" class="text-sm text-primary">
            {{ status }}
        </p>

        <Form
            action="/magic-login"
            method="post"
            class="crm-form"
            v-slot="{ errors, processing }"
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
                <Button type="submit" class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    Email me a sign-in link
                </Button>
            </div>
        </Form>

        <TextLink :href="login()" class="text-center text-sm">
            Sign in with a password
        </TextLink>
    </div>
</template>
