<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
const page = usePage();
const brand = computed(
    () =>
        page.props.brand as {
            name: string;
            logo: string;
            lead_label: string;
        },
);
const user = computed(
    () => (page.props.auth as { user: { id: number; name: string } }).user,
);
const capabilities = computed(() => page.props.capabilities as string[]);
const navigation = computed(() => [
    { label: "Overview", href: "/dashboard", icon: "space_dashboard" },
    ...(page.props.salesAvailable !== false
        ? [
              {
                  label: brand.value.lead_label,
                  href: "/leads",
                  icon: "view_list",
              },
              { label: "Follow-ups", href: "/tasks", icon: "task_alt" },
          ]
        : []),
    { label: "Reminders", href: "/reminders", icon: "notifications" },
    ...(capabilities.value.some((permission) =>
        ["imports.manage", "exports.view"].includes(permission),
    )
        ? [
              {
                  label: "Import / export",
                  href: "/spreadsheets",
                  icon: "import_export",
              },
          ]
        : []),
    ...(page.props.moduleNavigation as { label: string; href: string }[]).map(
        (item) => ({ ...item, icon: "extension" }),
    ),
]);
const initials = computed(() =>
    user.value.name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => Array.from(part)[0])
        .join("")
        .toLocaleUpperCase(),
);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const isMobile = useMediaQuery("(max-width: 767px)");
const collapsed = ref(false);
const mobileOpen = ref(false);
const sidebar = ref<HTMLElement>();
const railCollapsed = computed(() => collapsed.value && !isMobile.value);
let ready = false;
function storageKey() {
    return `basecrm.sidebar.collapsed.${window.location.origin}.${user.value.id}`;
}
onMounted(() => {
    try {
        collapsed.value = localStorage.getItem(storageKey()) === "true";
    } catch {
        /* Storage may be unavailable; navigation still works. */
    }
    ready = true;
});
watch(collapsed, (value) => {
    if (!ready) return;
    try {
        localStorage.setItem(storageKey(), String(value));
    } catch {
        /* Keep the current session's choice. */
    }
});
function selected(href: string) {
    const current = page.url.split("?")[0];
    if (
        href === "/leads" &&
        (current === "/people" || current.startsWith("/people/"))
    )
        return true;
    return current === href || current.startsWith(`${href}/`);
}
function openNavigation() {
    if (sidebar.value instanceof HTMLDialogElement) {
        sidebar.value.showModal();
        mobileOpen.value = true;
    }
}
function closeNavigation() {
    if (sidebar.value instanceof HTMLDialogElement && sidebar.value.open)
        sidebar.value.close();
    mobileOpen.value = false;
}
function toggleNavigation() {
    if (isMobile.value) closeNavigation();
    else collapsed.value = !collapsed.value;
}
function backdropClick(event: MouseEvent) {
    if (!isMobile.value || !sidebar.value || event.target !== sidebar.value)
        return;
    const bounds = sidebar.value.getBoundingClientRect();
    if (
        event.clientX < bounds.left ||
        event.clientX > bounds.right ||
        event.clientY < bounds.top ||
        event.clientY > bounds.bottom
    )
        closeNavigation();
}
watch(() => page.url, closeNavigation);
watch(isMobile, closeNavigation);
onBeforeUnmount(closeNavigation);
</script>
<template>
    <div class="crm" :class="{ 'crm-sidebar-collapsed': railCollapsed }">
        <component
            :is="isMobile ? 'dialog' : 'aside'"
            id="crm-sidebar"
            ref="sidebar"
            class="crm-sidebar"
            :aria-label="isMobile ? 'Navigation' : undefined"
            @close="mobileOpen = false"
            @click="backdropClick"
        >
            <div class="crm-sidebar-top">
                <Link
                    v-if="!railCollapsed"
                    href="/dashboard"
                    @click="closeNavigation"
                    class="crm-brand"
                    :aria-label="brand.name"
                >
                    <img
                        :src="brand.logo"
                        alt=""
                        width="36"
                        height="36"
                    /><span>{{ brand.name }}</span>
                </Link>
                <button
                    class="crm-icon-control crm-sidebar-toggle"
                    :aria-label="
                        isMobile
                            ? 'Close navigation'
                            : railCollapsed
                              ? 'Expand sidebar'
                              : 'Collapse sidebar'
                    "
                    :title="
                        isMobile
                            ? 'Close navigation'
                            : railCollapsed
                              ? 'Expand sidebar'
                              : 'Collapse sidebar'
                    "
                    :aria-expanded="isMobile ? mobileOpen : !railCollapsed"
                    aria-controls="crm-sidebar"
                    @click="toggleNavigation"
                >
                    <span
                        class="material-symbols-outlined"
                        aria-hidden="true"
                        >{{
                            isMobile
                                ? "close"
                                : railCollapsed
                                  ? "menu"
                                  : "menu_open"
                        }}</span
                    >
                </button>
            </div>
            <nav aria-label="Main navigation">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    @click="closeNavigation"
                    :class="{ selected: selected(item.href) }"
                    :aria-current="selected(item.href) ? 'page' : undefined"
                    :aria-label="item.label"
                    :title="railCollapsed ? item.label : undefined"
                >
                    <span
                        class="material-symbols-outlined"
                        aria-hidden="true"
                        >{{ item.icon }}</span
                    ><span class="crm-nav-label">{{ item.label }}</span>
                </Link>
            </nav>
            <div class="crm-sidebar-bottom">
                <Link
                    v-if="capabilities.includes('settings.manage')"
                    href="/business-settings"
                    @click="closeNavigation"
                    :class="{ selected: selected('/business-settings') }"
                    :aria-current="
                        selected('/business-settings') ? 'page' : undefined
                    "
                    aria-label="Business settings"
                    :title="railCollapsed ? 'Business settings' : undefined"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >settings</span
                    ><span class="crm-nav-label">Business settings</span>
                </Link>
                <Link
                    href="/settings/security"
                    @click="closeNavigation"
                    :class="{ selected: selected('/settings/security') }"
                    :aria-current="
                        selected('/settings/security') ? 'page' : undefined
                    "
                    aria-label="Account settings"
                    :title="railCollapsed ? 'Account settings' : undefined"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >security</span
                    ><span class="crm-nav-label">Account settings</span>
                </Link>
                <Link
                    href="/settings/profile"
                    @click="closeNavigation"
                    class="crm-profile-link"
                    :class="{ selected: selected('/settings/profile') }"
                    :aria-current="
                        selected('/settings/profile') ? 'page' : undefined
                    "
                    :aria-label="`Profile: ${user.name}`"
                    :title="railCollapsed ? `Profile: ${user.name}` : undefined"
                >
                    <span class="crm-avatar" aria-hidden="true">{{
                        initials
                    }}</span
                    ><span class="crm-nav-label">{{ user.name }}</span>
                </Link>
                <Link
                    href="/logout"
                    @click="closeNavigation"
                    method="post"
                    as="button"
                    aria-label="Sign out"
                    :title="railCollapsed ? 'Sign out' : undefined"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >logout</span
                    ><span class="crm-nav-label">Sign out</span>
                </Link>
            </div>
        </component>
        <main class="crm-main">
            <button
                class="crm-icon-control crm-mobile-menu"
                aria-label="Open navigation"
                aria-controls="crm-sidebar"
                aria-haspopup="dialog"
                :aria-expanded="mobileOpen"
                @click="openNavigation"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >menu</span
                >
            </button>
            <div v-if="errors.length" class="crm-error" role="alert">
                <p v-for="error in errors" :key="String(error)">{{ error }}</p>
            </div>
            <div class="crm-content"><slot /></div>
        </main>
    </div>
</template>
