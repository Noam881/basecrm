<script setup lang="ts">
import type {
    DropdownMenuRadioItemEmits,
    DropdownMenuRadioItemProps,
} from "reka-ui";
import type { HTMLAttributes } from "vue";
import { Circle } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import {
    DropdownMenuItemIndicator,
    DropdownMenuRadioItem,
    useForwardPropsEmits,
} from "reka-ui";
import { cn } from "@/lib/utils";

const props = defineProps<
    DropdownMenuRadioItemProps & { class?: HTMLAttributes["class"] }
>();

const emits = defineEmits<DropdownMenuRadioItemEmits>();

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <DropdownMenuRadioItem
        data-slot="dropdown-menu-radio-item"
        v-bind="forwarded"
        :class="
            cn('crm-material-menu-item crm-material-menu-choice', props.class)
        "
    >
        <span class="crm-material-menu-indicator">
            <DropdownMenuItemIndicator>
                <slot name="indicator-icon">
                    <Circle class="size-2 fill-current" />
                </slot>
            </DropdownMenuItemIndicator>
        </span>
        <slot />
    </DropdownMenuRadioItem>
</template>
