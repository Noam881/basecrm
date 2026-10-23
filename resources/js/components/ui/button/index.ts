import type { VariantProps } from "class-variance-authority";
import { cva } from "class-variance-authority";

export { default as Button } from "./Button.vue";

export const buttonVariants = cva("crm-control", {
    variants: {
        variant: {
            default: "crm-button",
            destructive: "crm-destructive",
            outline: "crm-secondary",
            secondary: "crm-tonal",
            ghost: "crm-ghost",
            link: "crm-text",
        },
        size: {
            default: "",
            sm: "crm-control-sm",
            lg: "crm-control-lg",
            icon: "crm-control-icon",
            "icon-sm": "crm-control-icon-sm",
            "icon-lg": "crm-control-icon-lg",
        },
    },
    defaultVariants: {
        variant: "default",
        size: "default",
    },
});
export type ButtonVariants = VariantProps<typeof buttonVariants>;
