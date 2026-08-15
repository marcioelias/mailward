<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

defineProps<{ title?: string }>()

interface NavItem {
    label: string
    route: string
}

/**
 * Grows as features land. An entry appears here only once its route exists,
 * so the navigation never offers a screen that is not implemented.
 */
const navigation: NavItem[] = []

const page = usePage()

const currentPath = computed(() => new URL(page.url, 'http://localhost').pathname)

const isCurrent = (route: string): boolean =>
    currentPath.value === route || currentPath.value.startsWith(`${route}/`)
</script>

<template>
    <div class="min-h-full">
        <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex max-w-6xl items-center gap-8 px-6 py-4">
                <Link href="/" class="text-lg font-semibold tracking-tight"> Mailward </Link>

                <nav v-if="navigation.length" class="flex gap-1">
                    <Link
                        v-for="item in navigation"
                        :key="item.route"
                        :href="item.route"
                        class="rounded-md px-3 py-1.5 text-sm transition-colors"
                        :class="
                            isCurrent(item.route)
                                ? 'bg-slate-100 font-medium text-slate-900 dark:bg-slate-800 dark:text-slate-100'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-100'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-10">
            <h1 v-if="title" class="mb-8 text-2xl font-semibold tracking-tight">
                {{ title }}
            </h1>

            <slot />
        </main>
    </div>
</template>
