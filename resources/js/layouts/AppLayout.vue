<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import {
    MeAdminLayout,
    MeBrand,
    MeDropdownDivider,
    MeDropdownItem,
    MeNavItem,
    MeToasts,
    MeUserMenu,
} from "@my-eyes/vue";
import { computed } from "vue";

defineProps<{ title?: string; subtitle?: string }>();

const page = usePage();

const currentPath = computed(
    () => new URL(page.url, "http://localhost").pathname,
);

/** MeNavItem requires `active` explicitly; it does not compare URLs itself. */
const isCurrent = (route: string): boolean =>
    currentPath.value === route || currentPath.value.startsWith(`${route}/`);

const actor = computed(
    () => (page.props as { actor?: { address?: string; name?: string } }).actor,
);

const signOut = (): void => {
    router.post("/logout");
};
</script>

<!--
    Every link here renders as Inertia's Link through the `as` prop rather than
    as a plain anchor, so navigation swaps the page without reloading the
    document. The package never detects the router itself, which is what keeps
    it usable from Blade, Livewire and Vue alike.
-->
<template>
    <MeAdminLayout :heading="title" :subheading="subtitle">
        <template #brand>
            <MeBrand :as="Link" href="/" name="Mailward" />
        </template>

        <template #nav>
            <MeNavItem
                :as="Link"
                href="/"
                icon="home"
                :active="currentPath === '/'"
            >
                Status
            </MeNavItem>

            <MeNavItem
                :as="Link"
                href="/domains"
                icon="mail"
                :active="isCurrent('/domains')"
            >
                Domains
            </MeNavItem>

            <MeNavItem
                :as="Link"
                href="/mailboxes"
                icon="users"
                :active="isCurrent('/mailboxes')"
            >
                Mailboxes
            </MeNavItem>

            <MeNavItem
                :as="Link"
                href="/alias-domains"
                icon="chevron-right"
                :active="isCurrent('/alias-domains')"
            >
                Alias domains
            </MeNavItem>
        </template>

        <template #user>
            <MeUserMenu
                :name="actor?.name || actor?.address || ''"
                :email="actor?.address ?? ''"
            >
                <MeDropdownItem :as="Link" href="/account" icon="user">
                    Your account
                </MeDropdownItem>
                <MeDropdownDivider />
                <MeDropdownItem icon="log-out" @click="signOut"
                    >Sign out</MeDropdownItem
                >
            </MeUserMenu>
        </template>

        <slot />

        <MeToasts position="bottom-end" />
    </MeAdminLayout>
</template>
