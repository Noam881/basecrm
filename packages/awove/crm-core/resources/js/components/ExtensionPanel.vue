<script setup lang="ts">
import { computed, defineAsyncComponent } from "vue";
const props = defineProps<{
    component: string;
    endpoint: string;
    leadId: number;
    editable: boolean;
}>();
const installed = import.meta.glob(
    "../../../../../../vendor/awove/*/resources/js/panels/**/*.vue",
);
const resolved = computed(() => {
    const loader = Object.entries(installed).find(([p]) =>
        p.endsWith(`/panels/${props.component}.vue`),
    )?.[1];
    return loader
        ? defineAsyncComponent(
              loader as Parameters<typeof defineAsyncComponent>[0],
          )
        : null;
});
</script>
<template>
    <component
        :is="resolved"
        v-if="resolved"
        :endpoint="endpoint.replace('{lead}', String(leadId))"
        :editable="editable"
    />
    <p v-else role="alert">This panel is unavailable in the current build.</p>
</template>
