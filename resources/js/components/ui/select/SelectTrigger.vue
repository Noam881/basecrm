<script setup lang="ts">
import type { SelectTriggerProps } from "reka-ui";
import type { HTMLAttributes } from "vue";
import { ChevronDown } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { SelectIcon, SelectTrigger, useForwardProps } from "reka-ui";
import { cn } from "@/lib/utils";

const props = withDefaults(
    defineProps<
        SelectTriggerProps & {
            class?: HTMLAttributes["class"];
            size?: "sm" | "default";
        }
    >(),
    { size: "default" },
);

const delegatedProps = reactiveOmit(props, "class", "size");
const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
    <SelectTrigger
        data-slot="select-trigger"
        :data-size="size"
        v-bind="forwardedProps"
        :class="cn('crm-input crm-material-select-trigger', props.class)"
    >
        <slot />
        <SelectIcon as-child>
            <ChevronDown class="size-4 opacity-50" />
        </SelectIcon>
    </SelectTrigger>
</template>
