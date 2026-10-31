<script setup lang="ts">
import { t } from "@/lib/i18n";
import type { DialogContentEmits, DialogContentProps } from "reka-ui";
import type { HTMLAttributes } from "vue";
import { X } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    useForwardPropsEmits,
} from "reka-ui";
import { cn } from "@/lib/utils";

defineOptions({
    inheritAttrs: false,
});

const props = defineProps<
    DialogContentProps & { class?: HTMLAttributes["class"] }
>();
const emits = defineEmits<DialogContentEmits>();

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <DialogPortal>
        <DialogOverlay
            class="crm-material-overlay grid place-items-center overflow-y-auto"
        >
            <DialogContent
                :class="
                    cn(
                        'crm-material-dialog crm-material-dialog-scroll',
                        props.class,
                    )
                "
                v-bind="{ ...$attrs, ...forwarded }"
                @pointer-down-outside="
                    (event) => {
                        const originalEvent = event.detail.originalEvent;
                        const target = originalEvent.target as HTMLElement;
                        if (
                            originalEvent.offsetX > target.clientWidth ||
                            originalEvent.offsetY > target.clientHeight
                        ) {
                            event.preventDefault();
                        }
                    }
                "
            >
                <slot />

                <DialogClose class="crm-material-popup-close">
                    <X class="w-4 h-4" />
                    <span class="sr-only">{{ t("Close") }}</span>
                </DialogClose>
            </DialogContent>
        </DialogOverlay>
    </DialogPortal>
</template>
