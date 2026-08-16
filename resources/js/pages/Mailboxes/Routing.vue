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
    MeField,
    MeInput,
    MeModal,
} from "@my-eyes/vue";
import { ref } from "vue";

/*
 * Two concepts, one table — and they are presented as two, never merged into a
 * single "forwardings" list (docs/02-domain.md §5, BR-01):
 *
 *   an alias      an extra address that delivers INTO this account
 *   a forwarding  a destination this account's mail is sent ON to
 *
 * The account's self-referencing row is not in `forwardings` below. It is what
 * makes the account receive its own mail, and the server excludes it from the
 * listing and refuses to remove it (BR-06).
 *
 * Neither list offers an enable/disable control. A row is present or absent,
 * and nothing sourced says iRedMail honours `active` on these rows, so a
 * suspend button would be a promise Mailward cannot keep (BR-19).
 */
interface AliasRow {
    id: number;
    address: string;
    active: boolean;
}

interface ForwardingRow {
    id: number;
    forwarding: string;
    active: boolean;
}

const props = defineProps<{
    mailbox: { username: string; name: string | null; domain: string };
    aliases: AliasRow[];
    forwardings: ForwardingRow[];
    status?: string;
}>();

const aliasForm = useForm({ address: "" });
const forwardingForm = useForm({ forwarding: "" });

const addAlias = (): void => {
    aliasForm.post(`/mailboxes/${props.mailbox.username}/aliases`, {
        preserveScroll: true,
        onSuccess: () => aliasForm.reset(),
    });
};

const addForwarding = (): void => {
    forwardingForm.post(`/mailboxes/${props.mailbox.username}/forwardings`, {
        preserveScroll: true,
        onSuccess: () => forwardingForm.reset(),
    });
};

const pendingAlias = ref<AliasRow | null>(null);
const pendingForwarding = ref<ForwardingRow | null>(null);

const confirmAliasRemoval = (): void => {
    const row = pendingAlias.value;

    if (row) {
        router.delete(
            `/mailboxes/${props.mailbox.username}/aliases/${row.id}`,
            { preserveScroll: true },
        );
        pendingAlias.value = null;
    }
};

const confirmForwardingRemoval = (): void => {
    const row = pendingForwarding.value;

    if (row) {
        router.delete(
            `/mailboxes/${props.mailbox.username}/forwardings/${row.id}`,
            { preserveScroll: true },
        );
        pendingForwarding.value = null;
    }
};
</script>

<template>
    <Head :title="`Aliases and forwardings — ${mailbox.username}`" />
    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <div class="me-row me-row--between">
            <div>
                <strong>{{ mailbox.username }}</strong>
                <div v-if="mailbox.name" class="me-hint">
                    {{ mailbox.name }}
                </div>
            </div>

            <Link href="/mailboxes" class="me-btn me-btn--ghost"
                >Back to mailboxes</Link
            >
        </div>

        <MeCard
            title="Aliases"
            description="Extra addresses that deliver into this account. They count against no domain limit."
        >
            <div class="me-stack">
                <div v-if="!aliases.length" class="me-empty">
                    <p>This account has no aliases.</p>
                </div>

                <table v-else class="me-table">
                    <thead>
                        <tr>
                            <th>Address</th>
                            <th class="me-table__cell--end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in aliases" :key="row.id">
                            <td>
                                {{ row.address }}
                                <!--
                                    Written outside Mailward: listed like any
                                    other row, with nothing offering to enable
                                    it (BR-19).
                                -->
                                <MeBadge v-if="!row.active" variant="warning">
                                    Inactive
                                </MeBadge>
                            </td>
                            <td class="me-table__cell--end">
                                <MeButton
                                    variant="ghost"
                                    size="sm"
                                    data-me-modal-open="confirm-alias-removal"
                                    @click="pendingAlias = row"
                                >
                                    Remove
                                </MeButton>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form class="me-row" @submit.prevent="addAlias">
                    <MeField
                        label="New alias address"
                        for="alias-address"
                        :error="aliasForm.errors.address"
                        hint="Its domain must already be hosted on this server."
                    >
                        <MeInput
                            id="alias-address"
                            v-model="aliasForm.address"
                            type="email"
                            :placeholder="`sales@${mailbox.domain}`"
                            required
                        />
                    </MeField>

                    <MeButton
                        type="submit"
                        variant="primary"
                        :disabled="aliasForm.processing"
                    >
                        Add alias
                    </MeButton>
                </form>
            </div>
        </MeCard>

        <MeCard
            title="Forwardings"
            description="Where this account's mail is sent on to. External destinations are allowed; a local one must already exist."
        >
            <div class="me-stack">
                <div v-if="!forwardings.length" class="me-empty">
                    <p>This account forwards its mail nowhere.</p>
                </div>

                <table v-else class="me-table">
                    <thead>
                        <tr>
                            <th>Destination</th>
                            <th class="me-table__cell--end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in forwardings" :key="row.id">
                            <td>
                                {{ row.forwarding }}
                                <MeBadge v-if="!row.active" variant="warning">
                                    Inactive
                                </MeBadge>
                            </td>
                            <td class="me-table__cell--end">
                                <MeButton
                                    variant="ghost"
                                    size="sm"
                                    data-me-modal-open="confirm-forwarding-removal"
                                    @click="pendingForwarding = row"
                                >
                                    Remove
                                </MeButton>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form class="me-row" @submit.prevent="addForwarding">
                    <MeField
                        label="Forward to"
                        for="forwarding-target"
                        :error="forwardingForm.errors.forwarding"
                        hint="Any address. If it is on this server it has to exist already."
                    >
                        <MeInput
                            id="forwarding-target"
                            v-model="forwardingForm.forwarding"
                            type="email"
                            placeholder="someone@example.com"
                            required
                        />
                    </MeField>

                    <MeButton
                        type="submit"
                        variant="primary"
                        :disabled="forwardingForm.processing"
                    >
                        Add forwarding
                    </MeButton>
                </form>

                <p class="me-hint">
                    The account keeps receiving its own mail. That is a row
                    Mailward maintains for you, and it is not listed here
                    because removing it would silently stop delivery.
                </p>
            </div>
        </MeCard>
    </div>

    <MeModal
        id="confirm-alias-removal"
        variant="danger"
        icon="alert-triangle"
        :title="`Remove ${pendingAlias?.address}?`"
        confirm="Remove the alias"
        cancel="Keep it"
        @confirm="confirmAliasRemoval"
    >
        <p>
            Mail sent to this address stops being delivered to
            {{ mailbox.username }} immediately. The account itself is
            unaffected.
        </p>
    </MeModal>

    <MeModal
        id="confirm-forwarding-removal"
        variant="danger"
        icon="alert-triangle"
        :title="`Stop forwarding to ${pendingForwarding?.forwarding}?`"
        confirm="Remove the forwarding"
        cancel="Keep it"
        @confirm="confirmForwardingRemoval"
    >
        <p>
            Mail arriving for {{ mailbox.username }} stops being sent on to this
            destination. Nothing already delivered is affected.
        </p>
    </MeModal>
</template>
