<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import { computed } from "vue";

defineProps<{ title?: string; subtitle?: string }>();

interface NavItem {
    label: string;
    route: string;
}

/**
 * Grows as features land. An entry appears here only once its route exists,
 * so the navigation never offers a screen that is not implemented.
 */
const navigation: NavItem[] = [{ label: "Domains", route: "/domains" }];

const page = usePage();

const currentPath = computed(
    () => new URL(page.url, "http://localhost").pathname,
);

const isCurrent = (route: string): boolean =>
    currentPath.value === route || currentPath.value.startsWith(`${route}/`);

const signOut = (): void => {
    router.post("/logout");
};
</script>

<!--
    The admin shell exists as a Blade component in my-eyes but not as a Vue one
    — the Vue package covers the table and the primitives around it. So the
    markup is rendered here against the package's own classes rather than
    hand-written styling, which is what keeps this from drifting away from the
    Blade side of the design system.
-->
<template>
    <div class="me-shell">
        <aside class="me-sidebar">
            <div class="me-sidebar__header">
                <Link href="/" class="me-sidebar__brand">Mailward</Link>
            </div>

            <div class="me-sidebar__body">
                <nav v-if="navigation.length" class="me-nav">
                    <Link
                        v-for="item in navigation"
                        :key="item.route"
                        :href="item.route"
                        class="me-nav__item"
                        :aria-current="
                            isCurrent(item.route) ? 'page' : undefined
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
        </aside>

        <div class="me-shell__main">
            <header class="me-topbar">
                <span class="me-topbar__title">{{ title }}</span>
                <span class="me-topbar__spacer" />

                <!--
                    The theme control is a DOM binding from @my-eyes/core rather
                    than a Vue component: any element carrying data-me-theme
                    cycles system, light and dark.
                -->
                <button
                    type="button"
                    class="me-btn me-btn--ghost me-btn--icon"
                    data-me-theme
                >
                    <span class="me-theme-icon-system">◐</span>
                    <span class="me-theme-icon-light">☀</span>
                    <span class="me-theme-icon-dark">☾</span>
                    <span class="me-sr-only">Toggle theme</span>
                </button>

                <button
                    type="button"
                    class="me-btn me-btn--ghost me-btn--sm"
                    @click="signOut"
                >
                    Sign out
                </button>
            </header>

            <main class="me-content">
                <div v-if="title" class="me-content__header">
                    <h1 class="me-content__heading">{{ title }}</h1>
                    <p v-if="subtitle" class="me-content__subheading">
                        {{ subtitle }}
                    </p>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
