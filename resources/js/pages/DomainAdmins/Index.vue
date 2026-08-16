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
import { Head, Link, router, setLayoutProps } from "@inertiajs/vue3";
import { MeAlert, MeBadge, MeButton, MeInput, MeModal } from "@my-eyes/vue";
import { ref, watch } from "vue";

interface AdministratorRow {
    address: string;
    name: string | null;
    isadmin: boolean;
    isglobaladmin: boolean;
    domains: string[];
    can: {
        manage: boolean;
        promoteToGlobal: boolean;
        revokeGlobal: boolean;
        demote: boolean;
    };
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    administrators: Paginated<AdministratorRow>;
    filters: { search: string };
    can: { create: boolean };
    status?: string;
}>();

setLayoutProps({
    title: "Administrators",
    subtitle: "Mail accounts that may sign in to Mailward.",
});

const search = ref(props.filters.search);

let debounce: ReturnType<typeof setTimeout>;
const reload = (): void => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            "/admins",
            { search: search.value },
            { preserveState: true, replace: true },
        );
    }, 350);
};

watch(search, reload);

const encode = (address: string): string => encodeURIComponent(address);

const promoteToGlobal = (row: AdministratorRow): void => {
    router.post(
        `/admins/${encode(row.address)}/global`,
        {},
        { preserveScroll: true },
    );
};

const revokeGlobal = (row: AdministratorRow): void => {
    router.delete(`/admins/${encode(row.address)}/global`, {
        preserveScroll: true,
    });
};

const pendingDemotion = ref<AdministratorRow | null>(null);

const confirmDemotion = (): void => {
    const row = pendingDemotion.value;

    if (row) {
        router.delete(`/admins/${encode(row.address)}`);
        pendingDemotion.value = null;
    }
};
</script>

<template>
    <Head title="Administrators" />
    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <div class="me-row me-row--between">
            <MeInput
                v-model="search"
                type="search"
                placeholder="Search administrators…"
            />

            <Link
                v-if="can.create"
                href="/admins/create"
                class="me-btn me-btn--primary"
            >
                Promote an account
            </Link>
        </div>

        <div v-if="!administrators.data.length" class="me-empty">
            <p v-if="filters.search">Nothing matches that filter.</p>
            <p v-else>No administrators are visible to you.</p>
        </div>

        <table v-else class="me-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Role</th>
                    <th>Domains</th>
                    <th class="me-table__cell--end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in administrators.data" :key="row.address">
                    <td>
                        <strong>{{ row.address }}</strong>
                        <div v-if="row.name">{{ row.name }}</div>
                    </td>
                    <td>
                        <MeBadge
                            :variant="row.isglobaladmin ? 'success' : 'info'"
                        >
                            {{
                                row.isglobaladmin
                                    ? "Global admin"
                                    : "Domain admin"
                            }}
                        </MeBadge>
                    </td>
                    <td>
                        <!--
                            A global admin holds one 'ALL' row, which names no
                            domain. Their reach is resolved from the flag, so
                            this is every domain rather than the zero rows a
                            naive join would produce (BR-05).
                        -->
                        <span v-if="!row.domains.length">
                            No domains assigned
                        </span>
                        <span v-else>{{ row.domains.join(", ") }}</span>
                    </td>
                    <td class="me-table__cell--end">
                        <template v-if="row.can.manage">
                            <Link
                                :href="`/admins/${encode(row.address)}/edit`"
                                class="me-btn me-btn--ghost me-btn--sm"
                            >
                                Manage
                            </Link>
                            <MeButton
                                v-if="row.can.promoteToGlobal"
                                variant="ghost"
                                size="sm"
                                @click="promoteToGlobal(row)"
                            >
                                Make global
                            </MeButton>
                            <MeButton
                                v-if="row.can.revokeGlobal"
                                variant="ghost"
                                size="sm"
                                @click="revokeGlobal(row)"
                            >
                                Revoke global
                            </MeButton>
                            <MeButton
                                v-if="row.can.demote"
                                variant="ghost"
                                size="sm"
                                data-me-modal-open="confirm-demotion"
                                @click="pendingDemotion = row"
                            >
                                Demote
                            </MeButton>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>

        <nav v-if="administrators.links.length > 3" class="me-pagination">
            <Link
                v-for="link in administrators.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="me-pagination__item"
                :aria-current="link.active ? 'page' : undefined"
                v-html="link.label"
            />
        </nav>
    </div>

    <MeModal
        id="confirm-demotion"
        variant="danger"
        icon="alert-triangle"
        :title="`Demote ${pendingDemotion?.address}?`"
        confirm="Demote"
        cancel="Keep the panel"
        @confirm="confirmDemotion"
    >
        <p>
            <strong>{{ pendingDemotion?.address }}</strong> loses every assigned
            domain and can no longer sign in to Mailward.
        </p>
        <p>
            The mail account itself is untouched — it still receives mail, and
            its two-factor enrolment and preferences survive, so promoting it
            again restores exactly what it had.
        </p>
    </MeModal>
</template>
