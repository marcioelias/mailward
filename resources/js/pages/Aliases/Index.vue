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
import { Head, Link, router } from "@inertiajs/vue3";
import {
    MeAlert,
    MeBadge,
    MeButton,
    MeInput,
    MeModal,
    MeSelect,
} from "@my-eyes/vue";
import { computed, ref, watch } from "vue";

interface AliasRow {
    address: string;
    name: string | null;
    domain: string;
    active: boolean;
    members: number;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    aliases: Paginated<AliasRow>;
    filters: { search: string; domain: string };
    domains: { value: string; label: string }[];
    status?: string;
}>();

const search = ref(props.filters.search);
const domain = ref(props.filters.domain);

let debounce: ReturnType<typeof setTimeout>;
const reload = (): void => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            "/aliases",
            { search: search.value, domain: domain.value },
            { preserveState: true, replace: true },
        );
    }, 350);
};

watch([search, domain], reload);

/*
 * An alias with no members is a valid state, reachable by creating one and by
 * removing the last member. It is flagged rather than prevented, because it
 * accepts mail and delivers it nowhere (BR-20).
 */
const blackHoles = computed(
    () => props.aliases.data.filter((row) => row.members === 0).length,
);

const pendingDeletion = ref<AliasRow | null>(null);

const confirmDeletion = (): void => {
    const row = pendingDeletion.value;

    if (row) {
        router.delete(`/aliases/${row.address}`);
        pendingDeletion.value = null;
    }
};
</script>

<template>
    <Head title="Aliases" />
    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <MeAlert v-if="blackHoles" variant="warning">
            {{ blackHoles }} alias(es) below have no members: they accept mail
            and deliver it nowhere.
        </MeAlert>

        <div class="me-row me-row--between">
            <div class="me-row">
                <MeInput
                    v-model="search"
                    type="search"
                    placeholder="Search aliases…"
                />

                <MeSelect
                    v-model="domain"
                    :options="domains"
                    placeholder="Any domain"
                    clearable
                />
            </div>

            <Link href="/aliases/create" class="me-btn me-btn--primary">
                Add alias
            </Link>
        </div>

        <div v-if="!aliases.data.length" class="me-empty">
            <p v-if="filters.search || filters.domain">
                Nothing matches that filter.
            </p>
            <p v-else>No aliases are visible to you.</p>
        </div>

        <table v-else class="me-table">
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Delivers to</th>
                    <th>Status</th>
                    <th class="me-table__cell--end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in aliases.data" :key="row.address">
                    <td>
                        <strong>{{ row.address }}</strong>
                        <div v-if="row.name" class="me-hint">
                            {{ row.name }}
                        </div>
                    </td>
                    <td>
                        <MeBadge v-if="row.members === 0" variant="warning">
                            0 members — delivers nowhere
                        </MeBadge>
                        <span v-else>{{ row.members }} member(s)</span>
                    </td>
                    <td>
                        <MeBadge :variant="row.active ? 'success' : 'warning'">
                            {{ row.active ? "Active" : "Disabled" }}
                        </MeBadge>
                    </td>
                    <td class="me-table__cell--end">
                        <Link
                            :href="`/aliases/${row.address}/edit`"
                            class="me-btn me-btn--ghost me-btn--sm"
                        >
                            Edit
                        </Link>
                        <MeButton
                            variant="ghost"
                            size="sm"
                            data-me-modal-open="confirm-alias-deletion"
                            @click="pendingDeletion = row"
                        >
                            Delete
                        </MeButton>
                    </td>
                </tr>
            </tbody>
        </table>

        <nav v-if="aliases.links.length > 3" class="me-pagination">
            <Link
                v-for="link in aliases.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="me-pagination__item"
                :aria-current="link.active ? 'page' : undefined"
                v-html="link.label"
            />
        </nav>
    </div>

    <MeModal
        id="confirm-alias-deletion"
        variant="danger"
        icon="alert-triangle"
        :title="`Delete ${pendingDeletion?.address}?`"
        confirm="Delete it"
        cancel="Keep it"
        @confirm="confirmDeletion"
    >
        <p>
            This removes the alias, its
            <strong>{{ pendingDeletion?.members }} member(s)</strong> and every
            row elsewhere that delivers to
            <strong>{{ pendingDeletion?.address }}</strong> — another account's
            forwarding, or its membership of a second alias.
        </p>
        <p>
            No mailbox is deleted. Without the second part the address would
            survive as a live routing target after the alias is gone.
        </p>
    </MeModal>
</template>
