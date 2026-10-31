<script setup lang="ts">
import { t } from "@/lib/i18n";
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

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: t("Profile settings"),
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="t('Profile settings')" />

    <div class="crm-stack">
        <Heading
            variant="small"
            :title="t('Personal details')"
            :description="t('Update your name and email address')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="crm-form"
            v-slot="{ errors, processing }"
        >
            <div class="crm-field">
                <Label for="name">{{ t("Name") }}</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="t('Full name')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="crm-field">
                <Label for="email">{{ t("Email address") }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="t('Email address')"
                />
                <InputError :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="text-sm text-muted-foreground">
                    {{ t("Your email address is unverified.") }}
                    <Link
                        href="/login"
                        class="text-primary underline decoration-current underline-offset-4"
                    >
                        {{
                            t(
                                "Sign in with an email link to verify this address.",
                            )
                        }}
                    </Link>
                </p>
            </div>

            <div class="crm-form-action">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ t("Save") }}</Button
                >
            </div>
        </Form>
    </div>
</template>
