<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { MeAlert, MeBadge, MeCard } from "@my-eyes/vue";
import { computed } from "vue";
import AppLayout from "@/layouts/AppLayout.vue";

interface Backend {
    connected: boolean;
    driver: string;
    database: string | null;
    error: string | null;
    missingTables: string[];
    counts: Record<string, number>;
}

const props = defineProps<{ backend: Backend }>();

const healthy = computed(
    () => props.backend.connected && props.backend.missingTables.length === 0,
);

const driverLabel = computed(() => {
    const labels: Record<string, string> = {
        mysql: "MySQL",
        mariadb: "MariaDB",
        pgsql: "PostgreSQL",
    };

    return labels[props.backend.driver] ?? props.backend.driver;
});
</script>

<template>
    <Head title="Status" />

    <AppLayout
        title="Status"
        subtitle="Mailward is connected to the mail server through the database iRedMail created."
    >
        <div class="me-stack">
            <MeCard title="iRedMail database">
                <template #actions>
                    <MeBadge :variant="healthy ? 'success' : 'warning'">
                        {{ healthy ? "Connected" : "Attention" }}
                    </MeBadge>
                </template>

                <div>
                    <table v-if="backend.connected" class="me-table">
                        <tbody>
                            <tr>
                                <th scope="row">Backend</th>
                                <td>{{ driverLabel }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Database</th>
                                <td>{{ backend.database }}</td>
                            </tr>
                            <tr
                                v-for="(count, table) in backend.counts"
                                :key="table"
                            >
                                <th scope="row">{{ table }}</th>
                                <td>{{ count }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <MeAlert v-else variant="danger" class="me-stack--tight">
                        {{ backend.error }}
                    </MeAlert>

                    <MeAlert
                        v-if="backend.connected && backend.missingTables.length"
                        variant="warning"
                    >
                        Reachable, but this does not look like an iRedMail
                        account database — missing
                        {{ backend.missingTables.join(", ") }}.
                    </MeAlert>
                </div>
            </MeCard>

            <p class="me-hint">
                Domains are managed from this panel. The remaining features are
                specified in <code>docs/</code> and wait on the decisions
                recorded in <code>docs/reference/decisions-needed.md</code>.
            </p>
        </div>
    </AppLayout>
</template>
