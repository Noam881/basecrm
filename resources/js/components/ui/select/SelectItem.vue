<script setup lang="ts">
import type { SelectItemProps } from "reka-ui";
import type { HTMLAttributes } from "vue";
import { Check } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import {
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    useForwardProps,
} from "reka-ui";
import { cn } from "@/lib/utils";

const props = defineProps<
    SelectItemProps & { class?: HTMLAttributes["class"] }
>();

const delegatedProps = reactiveOmit(props, "class");

const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
    <SelectItem
        data-slot="select-item"
        v-bind="forwardedProps"
        :class="
            cn(
                'crm-material-menu-item crm-material-menu-choice-end',
                props.class,
            )
        "
    >
        <span class="crm-material-menu-indicator" data-align="end">
            <SelectItemIndicator>
                <slot name="indicator-icon">
                    <Check class="size-4" />
                </slot>
            </SelectItemIndicator>
        </span>

        <SelectItemText>
            <slot />
        </SelectItemText>
    </SelectItem>
</template>
