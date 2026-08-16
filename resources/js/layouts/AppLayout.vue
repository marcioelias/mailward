<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import {
    MeAdminLayout,
    MeDropdownDivider,
    MeDropdownItem,
    MeNavItem,
    MeToasts,
    MeUserMenu,
} from '@my-eyes/vue'
import { computed } from 'vue'

defineProps<{ title?: string; subtitle?: string }>()

const page = usePage()

const currentPath = computed(() => new URL(page.url, 'http://localhost').pathname)

/** MeNavItem requires `active` explicitly; it does not compare URLs itself. */
const isCurrent = (route: string): boolean =>
    currentPath.value === route || currentPath.value.startsWith(`${route}/`)

/**
 * MeNavItem renders a plain anchor, which would reload the whole document.
 * It spreads its attributes onto that anchor, so intercepting the click and
 * handing the visit back to Inertia is enough. A prop letting the component
 * render as an Inertia Link would remove this adapter — worth raising with
 * the package.
 */
const visit = (href: string) => (event: MouseEvent): void => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return
    }

    event.preventDefault()
    router.visit(href)
}

const actor = computed(
    () => (page.props as { actor?: { address?: string; name?: string } }).actor,
)

const signOut = (): void => {
    router.post('/logout')
}
</script>

<template>
    <MeAdminLayout :heading="title" :subheading="subtitle">
        <template #brand>
            <Link href="/">Mailward</Link>
        </template>

        <template #nav>
            <MeNavItem href="/" icon="home" :active="currentPath === '/'" @click="visit('/')">
                Status
            </MeNavItem>

            <MeNavItem
                href="/domains"
                icon="mail"
                :active="isCurrent('/domains')"
                @click="visit('/domains')"
            >
                Domains
            </MeNavItem>

            <MeNavItem
                href="/mailboxes"
                icon="users"
                :active="isCurrent('/mailboxes')"
                @click="visit('/mailboxes')"
            >
                Mailboxes
            </MeNavItem>

            <MeNavItem
                href="/alias-domains"
                icon="chevron-right"
                :active="isCurrent('/alias-domains')"
                @click="visit('/alias-domains')"
            >
                Alias domains
            </MeNavItem>
        </template>

        <template #user>
            <MeUserMenu :name="actor?.name || actor?.address || ''" :email="actor?.address ?? ''">
                <MeDropdownItem icon="user" @click="visit('/account')($event)">
                    Your account
                </MeDropdownItem>
                <MeDropdownDivider />
                <MeDropdownItem icon="log-out" @click="signOut">Sign out</MeDropdownItem>
            </MeUserMenu>
        </template>

        <slot />

        <MeToasts position="bottom-end" />
    </MeAdminLayout>
</template>
