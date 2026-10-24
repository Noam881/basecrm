<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";
import { computed, ref, useId, watch } from "vue";
import {
    DropdownMenuContent,
    DropdownMenuItemIndicator,
    DropdownMenuPortal,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from "reka-ui";
import type { Stage } from "../types";

const props = defineProps<{
    leadId: number;
    recordName: string;
    statusId: string;
    version: number;
    statuses: Stage[];
    editable: boolean;
}>();
const feedbackId = useId();
const feedback = ref("");
const needsRefresh = ref(false);
const refreshing = ref(false);
const permissionDenied = ref(false);
const form = useForm({ status_id: props.statusId, version: props.version });
const currentStage = computed(
    () =>
        props.statuses.find((stage) => stage.id === props.statusId)?.name ??
        props.statusId,
);
const error = computed(() =>
    Object.values(form.errors).filter(Boolean).join(" "),
);
watch(
    () => [props.statusId, props.version] as const,
    ([status, version]) => {
        form.status_id = status;
        form.version = version;
    },
);
function changeStage(value: unknown) {
    if (typeof value !== "string") return;
    if (
        !props.editable ||
        permissionDenied.value ||
        needsRefresh.value ||
        form.processing ||
        value === props.statusId
    )
        return;
    if (!props.statuses.some((stage) => stage.id === value)) return;
    form.clearErrors();
    feedback.value = "";
    form.status_id = value;
    form.version = props.version;
    form.patch(`/leads/${props.leadId}`, {
        errorBag: `lead_stage_${props.leadId}`,
        preserveScroll: true,
        onSuccess: () => {
            feedback.value = "Stage saved";
        },
        onHttpException: (response) => {
            if (response.status === 409) {
                needsRefresh.value = true;
                form.setError(
                    "status_id",
                    "This lead changed in another session. Refresh the stage before trying again; your other edits will be kept.",
                );
                return false;
            }
            if (response.status === 403) {
                denyPermission();
                return false;
            }
        },
        onNetworkError: () => {
            needsRefresh.value = true;
            form.setError(
                "status_id",
                "The connection was interrupted. Refresh the stage to check whether your change was saved.",
            );
            return false;
        },
        onFinish: () => {
            form.status_id = props.statusId;
            form.version = props.version;
        },
    });
}
function denyPermission() {
    permissionDenied.value = true;
    needsRefresh.value = false;
    form.setError(
        "status_id",
        "You no longer have permission to change this stage. Your change was not saved.",
    );
}
function refreshStage() {
    if (refreshing.value || form.processing) return;
    refreshing.value = true;
    router.reload({
        only: ["lead", "table", "editable", "statuses"],
        onSuccess: () => {
            needsRefresh.value = false;
            form.clearErrors();
            feedback.value = "Stage refreshed. Review it before changing it.";
        },
        onHttpException: (response) => {
            if (response.status === 403) {
                denyPermission();
                return false;
            }
        },
        onNetworkError: () => {
            form.setError(
                "status_id",
                "The stage could not be refreshed. Check your connection and try again.",
            );
            return false;
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <div class="crm-stage-control">
        <DropdownMenuRoot v-if="editable && !permissionDenied">
            <DropdownMenuTrigger
                as-child
                :disabled="form.processing || needsRefresh || refreshing"
            >
                <button
                    type="button"
                    class="crm-tonal crm-control-sm crm-stage-bubble"
                    :aria-label="`Change stage for ${recordName}; current stage: ${currentStage}`"
                    :aria-describedby="
                        error || feedback ? feedbackId : undefined
                    "
                    :aria-busy="form.processing"
                >
                    <span class="crm-stage-name">{{
                        form.processing ? "Saving…" : currentStage
                    }}</span>
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >expand_more</span
                    >
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuPortal>
                <DropdownMenuContent
                    class="crm-material-menu"
                    :aria-label="`Stage for ${recordName}`"
                    align="start"
                    :side-offset="4"
                    :collision-padding="12"
                >
                    <DropdownMenuRadioGroup
                        :model-value="statusId"
                        @update:model-value="changeStage"
                    >
                        <DropdownMenuRadioItem
                            v-for="stage in statuses"
                            :key="stage.id"
                            :value="stage.id"
                            class="crm-material-menu-item crm-material-menu-choice"
                        >
                            <span class="crm-material-menu-indicator">
                                <DropdownMenuItemIndicator>
                                    <span
                                        class="material-symbols-outlined"
                                        aria-hidden="true"
                                        >check</span
                                    >
                                </DropdownMenuItemIndicator>
                            </span>
                            {{ stage.name }}
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                </DropdownMenuContent>
            </DropdownMenuPortal>
        </DropdownMenuRoot>
        <span v-else class="crm-badge crm-stage-bubble">{{
            currentStage
        }}</span>
        <small v-if="error" :id="feedbackId" role="alert" class="crm-notice">{{
            error
        }}</small>
        <button
            v-if="needsRefresh"
            type="button"
            class="crm-ghost crm-control-sm"
            :disabled="refreshing"
            :aria-label="`Refresh stage for ${recordName}`"
            @click="refreshStage"
        >
            {{ refreshing ? "Refreshing…" : "Refresh stage" }}
        </button>
        <small
            v-if="feedback && !error"
            :id="feedbackId"
            role="status"
            class="crm-muted"
            >{{ feedback }}</small
        >
    </div>
</template>
