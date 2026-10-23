<script setup lang="ts">
import { Eye, EyeOff } from "@lucide/vue";
import { ref, useTemplateRef } from "vue";
import type { HTMLAttributes } from "vue";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes["class"];
    autofocus?: boolean;
}>();

const showPassword = ref(false);
const inputRef = useTemplateRef("inputRef");

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="crm-password">
        <Input
            v-focus="props.autofocus"
            ref="inputRef"
            :type="showPassword ? 'text' : 'password'"
            :class="cn('crm-input-password', props.class)"
            v-bind="$attrs"
        />
        <button
            type="button"
            @click="showPassword = !showPassword"
            class="crm-password-toggle"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            :aria-pressed="showPassword"
        >
            <EyeOff v-if="showPassword" class="size-4" />
            <Eye v-else class="size-4" />
        </button>
    </div>
</template>
