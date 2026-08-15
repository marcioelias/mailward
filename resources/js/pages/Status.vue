<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

interface Backend {
    connected: boolean
    driver: string
    database: string | null
    error: string | null
    missingTables: string[]
    counts: Record<string, number>
}

const props = defineProps<{ backend: Backend }>()

const healthy = computed(
    () => props.backend.connected && props.backend.missingTables.length === 0,
)

const driverLabel = computed(() => {
    const labels: Record<string, string> = {
        mysql: 'MySQL',
        mariadb: 'MariaDB',
        pgsql: 'PostgreSQL',
    }

    return labels[props.backend.driver] ?? props.backend.driver
})
</script>

<template>
    <Head title="Status" />

    <div class="mx-auto flex min-h-full max-w-3xl flex-col justify-center px-6 py-16">
        <header class="mb-10">
            <h1 class="text-3xl font-semibold tracking-tight">Mailward</h1>
            <p class="mt-2 text-slate-600 dark:text-slate-400">
                An administration panel for an existing iRedMail server.
            </p>
        </header>

        <section
            class="rounded-xl border p-6"
            :class="
                healthy
                    ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40'
                    : 'border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40'
            "
        >
            <h2 class="flex items-center gap-2 font-medium">
                <span
                    class="inline-block size-2 rounded-full"
                    :class="healthy ? 'bg-emerald-500' : 'bg-amber-500'"
                />
                iRedMail database
            </h2>

            <dl v-if="backend.connected" class="mt-4 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-slate-600 dark:text-slate-400">Backend</dt>
                <dd class="font-mono">{{ driverLabel }}</dd>

                <dt class="text-slate-600 dark:text-slate-400">Database</dt>
                <dd class="font-mono">{{ backend.database }}</dd>

                <template v-for="(count, table) in backend.counts" :key="table">
                    <dt class="text-slate-600 dark:text-slate-400">{{ table }}</dt>
                    <dd class="font-mono tabular-nums">{{ count }}</dd>
                </template>
            </dl>

            <p v-else class="mt-4 font-mono text-sm break-words text-amber-900 dark:text-amber-200">
                {{ backend.error }}
            </p>

            <p
                v-if="backend.connected && backend.missingTables.length"
                class="mt-4 text-sm text-amber-900 dark:text-amber-200"
            >
                Reachable, but this does not look like an iRedMail account
                database — missing
                <span class="font-mono">{{ backend.missingTables.join(', ') }}</span>.
            </p>
        </section>

        <section class="mt-8 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
            <p>
                Nothing is implemented beyond this page. The specification lives
                in <span class="font-mono">docs/</span> and is written before the
                code; the features it describes are waiting on decisions recorded
                in <span class="font-mono">docs/reference/decisions-needed.md</span>.
            </p>
            <p class="mt-3">
                Not affiliated with iRedMail.
            </p>
        </section>
    </div>
</template>
