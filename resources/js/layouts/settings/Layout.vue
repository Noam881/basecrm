<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { useCurrentUrl } from "@/composables/useCurrentUrl";
import { toUrl } from "@/lib/utils";
import { edit as editProfile } from "@/routes/profile";
import { edit as editSecurity } from "@/routes/security";
import type { NavItem } from "@/types";

const sidebarNavItems: NavItem[] = [
    {
        title: t("Profile"),
        href: editProfile(),
    },
    {
        title: t("Sign-in method"),
        href: editSecurity(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
const title = computed(() =>
    isCurrentOrParentUrl(editSecurity()) ? t("Account settings") : t("Profile"),
);
</script>

<template>
    <div>
        <div class="crm-heading">
            <h1>{{ title }}</h1>
        </div>

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="t('Settings')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        class="w-full crm-control-start"
                        :aria-current="
                            isCurrentOrParentUrl(item.href) ? 'page' : undefined
                        "
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
