<script lang="ts">
import AppLayout from "@/layouts/AppLayout.vue";

/*
 * A persistent layout. Rendering AppLayout inside the template instead would
 * destroy and rebuild the sidebar and topbar on every visit — the menu state
 * resets and the shell visibly redraws. Named here, Inertia keeps the instance
 * and swaps only the page inside it; its heading arrives through
 * setLayoutProps, since the render-function form cannot pass props in v3.
 */
export default { layout: AppLayout };
</script>

<script setup lang="ts">
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import {
    MeAlert,
    MeBadge,
    MeButton,
    MeInput,
    MeModal,
    MeSelect,
} from "@my-eyes/vue";
import { ref, watch } from "vue";
import RowAction from "@/components/RowAction.vue";

interface AliasDomainRow {
    alias_domain: string;
    target_domain: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    aliasDomains: Paginated<AliasDomainRow>;
    filters: { search: string; target_domain: string };
    targets: { value: string; label: string }[];
    can: { create: boolean };
    status?: string;
}>();

const search = ref(props.filters.search);
const target = ref(props.filters.target_domain);

let debounce: ReturnType<typeof setTimeout>;
const reload = (): void => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            "/alias-domains",
            { search: search.value, target_domain: target.value },
            { preserveState: true, replace: true },
        );
    }, 350);
};

watch([search, target], reload);

const toggle = useForm({ active: false });

const setActive = (row: AliasDomainRow): void => {
    toggle.active = !row.active;
    toggle.post(`/alias-domains/${row.alias_domain}/active`, {
        preserveScroll: true,
    });
};

const confirming = ref(false);
const pendingDeletion = ref<AliasDomainRow | null>(null);

const askToDelete = (row: any): void => {
    pendingDeletion.value = row;
    confirming.value = true;
};

const confirmDeletion = (): void => {
    const row = pendingDeletion.value;

    if (row) {
        router.delete(`/alias-domains/${row.alias_domain}`);
        pendingDeletion.value = null;
        confirming.value = false;
    }
};
</script>

<template>
    <Head title="Alias domains" />
    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <div class="me-row me-row--between">
            <div class="me-row">
                <MeInput
                    v-model="search"
                    type="search"
                    placeholder="Search alias domains…"
                />

                <MeSelect
                    v-model="target"
                    :options="targets"
                    placeholder="Any target domain"
                    clearable
                />
            </div>

            <Link
                v-if="can.create"
                href="/alias-domains/create"
                class="me-btn me-btn--primary"
            >
                Add alias domain
            </Link>
        </div>

        <div v-if="!aliasDomains.data.length" class="me-empty">
            <p v-if="filters.search || filters.target_domain">
                Nothing matches that filter.
            </p>
            <p v-else>No alias domains are visible to you.</p>
        </div>

        <table v-else class="me-table">
            <thead>
                <tr>
                    <th>Alias domain</th>
                    <th>Delivers to</th>
                    <th>Status</th>
                    <th class="me-table__cell--end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in aliasDomains.data" :key="row.alias_domain">
                    <td>
                        <strong>{{ row.alias_domain }}</strong>
                    </td>
                    <td>{{ row.target_domain }}</td>
                    <td>
                        <MeBadge :variant="row.active ? 'success' : 'warning'">
                            {{ row.active ? "Active" : "Disabled" }}
                        </MeBadge>
                    </td>
                    <td class="me-table__cell--end">
                        <template v-if="can.create">
                            <RowAction
                                label="Edit"
                                icon="edit"
                                :href="`/alias-domains/${row.alias_domain}/edit`"
                            />
                            <RowAction
                                :label="row.active ? 'Disable' : 'Enable'"
                                :icon="row.active ? 'disable' : 'enable'"
                                @click="setActive(row)"
                            />
                            <RowAction
                                label="Delete"
                                icon="delete"
                                variant="danger"
                                @click="askToDelete(row)"
                            />
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>

        <nav v-if="aliasDomains.links.length > 3" class="me-pagination">
            <Link
                v-for="link in aliasDomains.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="me-pagination__item"
                :aria-current="link.active ? 'page' : undefined"
                v-html="link.label"
            />
        </nav>
    </div>

    <MeModal
        id="confirm-alias-domain-deletion"
        v-model:open="confirming"
        @close="pendingDeletion = null"
        variant="danger"
        icon="alert-triangle"
        :title="`Delete ${pendingDeletion?.alias_domain}?`"
        confirm="Delete it"
        cancel="Keep it"
        @confirm="confirmDeletion"
    >
        <p>
            Mail addressed to
            <strong>{{ pendingDeletion?.alias_domain }}</strong> will stop being
            delivered to <strong>{{ pendingDeletion?.target_domain }}</strong
            >.
        </p>
        <p>
            No account is deleted — this removes the routing, not the mailboxes.
        </p>
    </MeModal>
</template>
