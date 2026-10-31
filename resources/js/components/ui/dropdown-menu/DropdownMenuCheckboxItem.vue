<script setup lang="ts">
import type {
    DropdownMenuCheckboxItemEmits,
    DropdownMenuCheckboxItemProps,
} from "reka-ui";
import type { HTMLAttributes } from "vue";
import { Check } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import {
    DropdownMenuCheckboxItem,
    DropdownMenuItemIndicator,
    useForwardPropsEmits,
} from "reka-ui";
import { cn } from "@/lib/utils";

const props = defineProps<
    DropdownMenuCheckboxItemProps & { class?: HTMLAttributes["class"] }
>();
const emits = defineEmits<DropdownMenuCheckboxItemEmits>();

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <DropdownMenuCheckboxItem
        data-slot="dropdown-menu-checkbox-item"
        v-bind="forwarded"
        :class="
            cn('crm-material-menu-item crm-material-menu-choice', props.class)
        "
    >
        <span class="crm-material-menu-indicator">
            <DropdownMenuItemIndicator>
                <slot name="indicator-icon">
                    <Check class="size-4" />
                </slot>
            </DropdownMenuItemIndicator>
        </span>
        <slot />
    </DropdownMenuCheckboxItem>
</template>
