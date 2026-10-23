<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui";
import type { HTMLAttributes } from "vue";
import { X } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import {
    DialogClose,
    DialogContent,
    DialogPortal,
    useForwardPropsEmits,
} from "reka-ui";
import { cn } from "@/lib/utils";
import SheetOverlay from "./SheetOverlay.vue";

interface SheetContentProps extends DialogContentProps {
    class?: HTMLAttributes["class"];
    side?: "top" | "right" | "bottom" | "left";
}

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(defineProps<SheetContentProps>(), {
    side: "right",
});
const emits = defineEmits<DialogContentEmits>();

const delegatedProps = reactiveOmit(props, "class", "side");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <DialogPortal>
        <SheetOverlay />
        <DialogContent
            data-slot="sheet-content"
            :data-side="side"
            :class="cn('crm-material-sheet', props.class)"
            v-bind="{ ...$attrs, ...forwarded }"
        >
            <slot />

            <DialogClose class="crm-material-popup-close">
                <X class="size-4" />
                <span class="sr-only">Close</span>
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
