<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner";
import {
    CircleCheckIcon,
    InfoIcon,
    Loader2Icon,
    OctagonXIcon,
    TriangleAlertIcon,
    XIcon,
} from "@lucide/vue";
import { Toaster as Sonner } from "vue-sonner";
import { cn } from "@/lib/utils";

import "vue-sonner/style.css";

const props = defineProps<ToasterProps>();
</script>

<template>
    <Sonner
        :class="cn('toaster group', props.class)"
        :style="{
            '--normal-bg': 'var(--crm-accent-container)',
            '--normal-text': 'var(--crm-on-accent-container)',
            '--normal-border': 'var(--crm-accent)',
            '--normal-bg-hover': 'var(--crm-accent-container)',
            '--normal-border-hover': 'var(--crm-accent)',
            '--success-bg': 'var(--crm-accent-container)',
            '--success-text': 'var(--crm-on-accent-container)',
            '--success-border': 'var(--crm-accent)',
            '--info-bg': 'var(--crm-accent-container)',
            '--info-text': 'var(--crm-on-accent-container)',
            '--info-border': 'var(--crm-accent)',
            '--warning-bg': 'var(--crm-accent-container)',
            '--warning-text': 'var(--crm-on-accent-container)',
            '--warning-border': 'var(--crm-accent)',
            '--error-bg': 'var(--crm-accent-container)',
            '--error-text': 'var(--crm-on-accent-container)',
            '--error-border': 'var(--crm-accent)',
            '--border-radius': 'var(--radius)',
        }"
        v-bind="props"
    >
        <template #success-icon>
            <CircleCheckIcon class="size-4" />
        </template>
        <template #info-icon>
            <InfoIcon class="size-4" />
        </template>
        <template #warning-icon>
            <TriangleAlertIcon class="size-4" />
        </template>
        <template #error-icon>
            <OctagonXIcon class="size-4" />
        </template>
        <template #loading-icon>
            <div>
                <Loader2Icon class="size-4 animate-spin" />
            </div>
        </template>
        <template #close-icon>
            <XIcon class="size-4" />
        </template>
    </Sonner>
</template>
