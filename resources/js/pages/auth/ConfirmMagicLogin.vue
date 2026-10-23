<script setup lang="ts">
import { Head, router } from "@inertiajs/vue3";
import { onMounted, ref } from "vue";
import TextLink from "@/components/TextLink.vue";
import { Spinner } from "@/components/ui/spinner";

defineOptions({
    layout: {
        title: "Sign in",
        description: "Use your email link to access your workspace.",
    },
});

const props = defineProps<{
    action: string;
    valid: boolean;
    email?: string | null;
}>();
const signingIn = ref(props.valid && Boolean(props.action));
let submitted = false;
const showFailure = () => {
    signingIn.value = false;
    return false;
};

onMounted(() => {
    if (submitted || !signingIn.value) return;
    submitted = true;
    router.post(
        props.action,
        {},
        {
            replace: true,
            preserveState: true,
            onError: showFailure,
            onCancel: showFailure,
            onHttpException: showFailure,
            onNetworkError: showFailure,
        },
    );
});
</script>

<template>
    <Head title="Sign in" />

    <div class="crm-stack">
        <template v-if="signingIn">
            <div role="status" class="crm-form-action">
                <Spinner aria-hidden="true" />
                <span>Signing you in…</span>
            </div>
            <p v-if="email" class="break-all text-sm text-muted-foreground">
                {{ email }}
            </p>
        </template>

        <template v-else>
            <p role="status" class="crm-notice-panel text-sm">
                This sign-in link is invalid or has expired. Request a new link
                to continue.
            </p>
            <TextLink href="/login" class="text-center text-sm">
                Request a new sign-in link
            </TextLink>
        </template>
    </div>
</template>
