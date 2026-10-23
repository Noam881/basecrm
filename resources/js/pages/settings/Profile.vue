<script setup lang="ts">
import { Form, Head, usePage } from "@inertiajs/vue3";
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import ProfileController from "@/actions/App/Http/Controllers/Settings/ProfileController";
import Heading from "@/components/Heading.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { edit } from "@/routes/profile";
import { send } from "@/routes/verification";

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: "Profile settings",
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <div class="crm-stack">
        <Heading
            variant="small"
            title="Personal details"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="crm-form"
            v-slot="{ errors, processing }"
        >
            <div class="crm-field">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="crm-field">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-neutral-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="crm-form-action">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>
</template>
