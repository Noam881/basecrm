<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import TextLink from "@/components/TextLink.vue";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";

defineOptions({
    layout: {
        title: "Confirm sign-in",
        description: "Finish signing in using the link from your email.",
    },
});

defineProps<{
    action: string;
    valid: boolean;
    email?: string | null;
}>();
</script>

<template>
    <Head title="Confirm sign-in" />

    <div class="crm-stack">
        <Form
            v-if="valid"
            :action="action"
            method="post"
            class="crm-form"
            v-slot="{ processing }"
        >
            <p v-if="email" class="text-sm text-muted-foreground">
                Sign in as
                <span class="break-all font-medium text-foreground">{{
                    email
                }}</span
                >.
            </p>
            <div class="crm-form-action">
                <Button type="submit" class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    Continue to sign in
                </Button>
            </div>
        </Form>

        <p v-else role="status" class="text-sm text-muted-foreground">
            This sign-in link is invalid or has expired. Request a new link to
            continue.
        </p>

        <TextLink href="/magic-login" class="text-center text-sm">
            Request a new sign-in link
        </TextLink>
    </div>
</template>
