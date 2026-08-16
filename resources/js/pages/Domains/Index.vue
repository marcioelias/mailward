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
    MeCard,
    MeInput,
    MeModal,
} from "@my-eyes/vue";
import { ref, watch } from "vue";
import RowAction from "@/components/RowAction.vue";

interface DomainRow {
    domain: string;
    description: string | null;
    active: boolean;
    backupmx: boolean;
    limits: { aliases: number; mailboxes: number; maillists: number };
    counts: { mailboxes: number; aliases: number; aliasDomains: number };
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const props = defineProps<{
    domains: Paginated<DomainRow>;
    filters: { search: string };
    can: { create: boolean };
    status?: string;
}>();

const search = ref(props.filters.search);

let debounce: ReturnType<typeof setTimeout>;
watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            "/domains",
            { search: value },
            { preserveState: true, replace: true },
        );
    }, 350);
});

/** `0` means unlimited, not "none allowed" (docs/02-domain.md §2). */
const limit = (value: number): string => (value === 0 ? "∞" : String(value));

const atLimit = (used: number, max: number): boolean => max > 0 && used >= max;

const toggle = useForm({ active: false });

const setActive = (row: DomainRow): void => {
    toggle.active = !row.active;
    toggle.post(`/domains/${row.domain}/active`, { preserveScroll: true });
};

/*
 * Deleting a domain destroys every account inside it, so the confirmation
 * names what will actually be lost rather than asking "are you sure".
 */
const confirming = ref(false);
const pendingDeletion = ref<DomainRow | null>(null);

const askToDelete = (row: any): void => {
    pendingDeletion.value = row;
    confirming.value = true;
};

const confirmDeletion = (): void => {
    const row = pendingDeletion.value;

    if (row) {
        router.delete(`/domains/${row.domain}`);
        pendingDeletion.value = null;
        confirming.value = false;
    }
};
</script>

<template>
    <Head title="Domains" />
    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <div class="me-row me-row--between">
            <MeInput
                v-model="search"
                type="search"
                placeholder="Search domains…"
            />

            <Link
                v-if="can.create"
                href="/domains/create"
                class="me-btn me-btn--primary"
            >
                Add domain
            </Link>
        </div>

        <div v-if="!domains.data.length" class="me-empty">
            <p v-if="filters.search">
                No domain matches “{{ filters.search }}”.
            </p>
            <p v-else>No domains are visible to you.</p>
        </div>

        <table v-else class="me-table">
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Mailboxes</th>
                    <th>Aliases</th>
                    <th>Status</th>
                    <th class="me-table__cell--end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in domains.data" :key="row.domain">
                    <td>
                        <strong>{{ row.domain }}</strong>
                        <div v-if="row.description" class="me-hint">
                            {{ row.description }}
                        </div>
                    </td>
                    <td>
                        <span
                            :class="{
                                'me-badge me-badge--warning': atLimit(
                                    row.counts.mailboxes,
                                    row.limits.mailboxes,
                                ),
                            }"
                        >
                            {{ row.counts.mailboxes }} /
                            {{ limit(row.limits.mailboxes) }}
                        </span>
                    </td>
                    <td>
                        <span
                            :class="{
                                'me-badge me-badge--warning': atLimit(
                                    row.counts.aliases,
                                    row.limits.aliases,
                                ),
                            }"
                        >
                            {{ row.counts.aliases }} /
                            {{ limit(row.limits.aliases) }}
                        </span>
                    </td>
                    <td>
                        <MeBadge :variant="row.active ? 'success' : 'warning'">
                            {{ row.active ? "Active" : "Disabled" }}
                        </MeBadge>
                        <MeBadge v-if="row.backupmx" variant="info"
                            >Backup MX</MeBadge
                        >
                    </td>
                    <td class="me-table__cell--end">
                        <template v-if="can.create">
                            <RowAction
                                label="Edit"
                                icon="edit"
                                :href="`/domains/${row.domain}/edit`"
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

        <nav v-if="domains.links.length > 3" class="me-pagination">
            <Link
                v-for="link in domains.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="me-pagination__item"
                :aria-current="link.active ? 'page' : undefined"
                v-html="link.label"
            />
        </nav>
    </div>

    <MeModal
        id="confirm-domain-deletion"
        v-model:open="confirming"
        @close="pendingDeletion = null"
        variant="danger"
        icon="alert-triangle"
        :title="`Delete ${pendingDeletion?.domain}?`"
        confirm="Delete the domain"
        cancel="Keep it"
        @confirm="confirmDeletion"
    >
        <p>
            This also deletes
            <strong>{{ pendingDeletion?.counts.mailboxes }} mailbox(es)</strong
            >,
            <strong>{{ pendingDeletion?.counts.aliases }} alias(es)</strong>
            and every forwarding in the domain.
        </p>
        <p>
            The mail files are removed afterwards by iRedMail's own cron job.
            This cannot be undone.
        </p>
    </MeModal>
</template>
