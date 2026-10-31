<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head } from "@inertiajs/vue3";
import Heading from "@/components/Heading.vue";
import { edit } from "@/routes/security";

defineProps<{
    email: string;
    signInMethod: "email_link";
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: t("Account settings"),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="t('Account settings')" />

    <div class="crm-flow">
        <Heading
            variant="small"
            :title="t('Account sign-in')"
            :description="t('Sign in using a link sent to your email address.')"
        />
        <dl>
            <dt class="text-sm text-muted-foreground">
                {{ t("Email address") }}
            </dt>
            <dd class="break-all">{{ email }}</dd>
        </dl>
    </div>
</template>
