<script setup lang="ts">
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
                title: "Account settings",
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Account settings" />

    <div class="crm-flow">
        <Heading
            variant="small"
            title="Account sign-in"
            description="Sign in using a link sent to your email address."
        />
        <dl>
            <dt class="text-sm text-muted-foreground">Email address</dt>
            <dd class="break-all">{{ email }}</dd>
        </dl>
    </div>
</template>
