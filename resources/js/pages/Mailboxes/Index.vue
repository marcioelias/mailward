<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { MeAlert, MeBadge, MeButton, MeInput, MeModal, MeSelect } from '@my-eyes/vue'
import { ref, watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'

interface MailboxRow {
    username: string
    name: string | null
    domain: string
    active: boolean
    isadmin: boolean
    isglobaladmin: boolean
    quota: number
    usedBytes: number
    weakness: string | null
}

interface Paginated<T> {
    data: T[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
}

const props = defineProps<{
    mailboxes: Paginated<MailboxRow>
    filters: { search: string; domain: string }
    domains: { value: string; label: string }[]
    status?: string
}>()

const search = ref(props.filters.search)
const domain = ref(props.filters.domain)

let debounce: ReturnType<typeof setTimeout>
watch([search, domain], () => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get(
            '/mailboxes',
            { search: search.value, domain: domain.value },
            { preserveState: true, replace: true },
        )
    }, 350)
})

/** Both are mebibytes; usage arrives in bytes because Dovecot writes it so. */
const quotaLabel = (row: MailboxRow): string => {
    const usedMib = Math.round(row.usedBytes / 1048576)

    return row.quota === 0 ? `${usedMib} MiB / ∞` : `${usedMib} / ${row.quota} MiB`
}

const overQuota = (row: MailboxRow): boolean =>
    row.quota > 0 && row.usedBytes / 1048576 >= row.quota * 0.9

const toggle = useForm({ active: false })

const setActive = (row: MailboxRow): void => {
    toggle.active = !row.active
    toggle.post(`/mailboxes/${row.username}/active`, { preserveScroll: true })
}

const pendingDeletion = ref<MailboxRow | null>(null)

const confirmDeletion = (): void => {
    const row = pendingDeletion.value

    if (row) {
        router.delete(`/mailboxes/${row.username}`)
        pendingDeletion.value = null
    }
}
</script>

<template>
    <Head title="Mailboxes" />

    <AppLayout title="Mailboxes" subtitle="Real mail accounts on this server.">
        <div class="me-stack">
            <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

            <div class="me-row me-row--between">
                <div class="me-row">
                    <MeInput v-model="search" type="search" placeholder="Search address or name…" />
                    <MeSelect
                        v-model="domain"
                        :options="domains"
                        placeholder="Any domain"
                        clearable
                    />
                </div>

                <Link href="/mailboxes/create" class="me-btn me-btn--primary">Add mailbox</Link>
            </div>

            <div v-if="!mailboxes.data.length" class="me-empty">
                <p v-if="filters.search || filters.domain">Nothing matches that filter.</p>
                <p v-else>No mailboxes are visible to you.</p>
            </div>

            <table v-else class="me-table">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Quota</th>
                        <th>Status</th>
                        <th class="me-table__cell--end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in mailboxes.data" :key="row.username">
                        <td>
                            <strong>{{ row.username }}</strong>
                            <div v-if="row.name" class="me-hint">{{ row.name }}</div>
                        </td>
                        <td :class="{ 'me-table__cell--numeric': true }">
                            <MeBadge v-if="overQuota(row)" variant="warning">
                                {{ quotaLabel(row) }}
                            </MeBadge>
                            <span v-else>{{ quotaLabel(row) }}</span>
                        </td>
                        <td>
                            <MeBadge :variant="row.active ? 'success' : 'warning'">
                                {{ row.active ? 'Active' : 'Disabled' }}
                            </MeBadge>
                            <MeBadge v-if="row.isglobaladmin" variant="info">Global admin</MeBadge>
                            <MeBadge v-else-if="row.isadmin" variant="info">Admin</MeBadge>

                            <!--
                                Not a failure — the account works. It is a
                                password weaker than the one Mailward writes,
                                which nothing else would ever tell anybody.
                            -->
                            <MeBadge v-if="row.weakness" variant="warning" :title="row.weakness">
                                Weak password
                            </MeBadge>
                        </td>
                        <td class="me-table__cell--end">
                            <Link
                                :href="`/mailboxes/${row.username}/edit`"
                                class="me-btn me-btn--ghost me-btn--sm"
                            >
                                Edit
                            </Link>
                            <MeButton variant="ghost" size="sm" @click="setActive(row)">
                                {{ row.active ? 'Disable' : 'Enable' }}
                            </MeButton>
                            <MeButton
                                variant="ghost"
                                size="sm"
                                data-me-modal-open="confirm-mailbox-deletion"
                                @click="pendingDeletion = row"
                            >
                                Delete
                            </MeButton>
                        </td>
                    </tr>
                </tbody>
            </table>

            <nav v-if="mailboxes.links.length > 3" class="me-pagination">
                <Link
                    v-for="link in mailboxes.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    class="me-pagination__item"
                    :aria-current="link.active ? 'page' : undefined"
                    v-html="link.label"
                />
            </nav>
        </div>

        <MeModal
            id="confirm-mailbox-deletion"
            variant="danger"
            icon="alert-triangle"
            :title="`Delete ${pendingDeletion?.username}?`"
            confirm="Delete the account"
            cancel="Keep it"
            @confirm="confirmDeletion"
        >
            <p>
                The account, its forwardings and any administrative grants it holds are removed
                immediately.
            </p>
            <p>
                The mail files are removed afterwards by iRedMail's own cron job, not by
                Mailward — so they survive for a while after the account is gone. This cannot be
                undone.
            </p>
        </MeModal>
    </AppLayout>
</template>
