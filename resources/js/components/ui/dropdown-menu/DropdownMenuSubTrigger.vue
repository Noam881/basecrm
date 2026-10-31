<script setup lang="ts">
import type { DropdownMenuSubTriggerProps } from "reka-ui";
import type { HTMLAttributes } from "vue";
import { ChevronRight } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { DropdownMenuSubTrigger, useForwardProps } from "reka-ui";
import { cn } from "@/lib/utils";

const props = defineProps<
    DropdownMenuSubTriggerProps & {
        class?: HTMLAttributes["class"];
        inset?: boolean;
    }
>();

const delegatedProps = reactiveOmit(props, "class", "inset");
const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
    <DropdownMenuSubTrigger
        data-slot="dropdown-menu-sub-trigger"
        v-bind="forwardedProps"
        :data-inset="inset ? '' : undefined"
        :class="cn('crm-material-menu-item', props.class)"
    >
        <slot />
        <ChevronRight class="ml-auto size-4" />
    </DropdownMenuSubTrigger>
</template>
