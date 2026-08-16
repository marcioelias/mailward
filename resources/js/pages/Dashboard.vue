<script lang="ts">
import AppLayout from "@/layouts/AppLayout.vue";

/*
 * A persistent layout. Rendering AppLayout inside the template instead would
 * destroy and rebuild the sidebar and topbar on every visit — the menu state
 * resets and the shell visibly redraws. Named here, Inertia keeps the instance
 * and swaps only the page inside it.
 */
export default { layout: AppLayout };
</script>

<script setup lang="ts">
import { Head, Link, setLayoutProps } from "@inertiajs/vue3";
import { MeAlert, MeBadge, MeCard, MeProgress } from "@my-eyes/vue";
import { computed } from "vue";

interface Count {
    total: number;
    inactive: number;
}

interface DomainUsage {
    domain: string;
    mailboxes: number;
    allocatedMib: number;
    usedBytes: number;
}

interface AtLimit {
    domain: string;
    mailboxes: { count: number; limit: number };
    aliases: { count: number; limit: number };
    reasons: string[];
}

interface Figures {
    domains: Count;
    mailboxes: Count;
    aliases: Count;
    quota: { allocatedMib: number; usedBytes: number };
    perDomain: DomainUsage[];
    atLimit: AtLimit[];
    dormancy: {
        thresholdDays: number;
        dormant: number;
        neverLoggedIn: number;
        unknown: number;
        degraded: boolean;
    };
}

const props = defineProps<{
    figures: Figures;
    can: { viewAuditLog: boolean };
}>();

setLayoutProps({
    title: "Dashboard",
    subtitle: "What this server holds, and what needs attention.",
});

const MIB = 1048576;

/*
 * `mailbox.quota` is mebibytes and `used_quota.bytes` is bytes. The conversion
 * happens once, here, and every label states its unit: read either as the
 * other and the figure is wrong by a factor of 1,048,576, with both readings
 * looking equally plausible on screen (docs/features/dashboard.md OQ-DASH-01).
 */
const usedMib = (bytes: number): number => Math.round(bytes / MIB);

const mib = (value: number): string => `${value.toLocaleString()} MiB`;

/** `0` means unlimited, not "none allowed" (docs/02-domain.md §2). */
const limit = (value: number): string =>
    value === 0 ? "∞" : value.toLocaleString();

/** Null when the domain allocates nothing, so no bar claims a percentage. */
const usagePercent = (row: DomainUsage): number | null =>
    row.allocatedMib > 0
        ? Math.min(
              100,
              Math.round((usedMib(row.usedBytes) / row.allocatedMib) * 100),
          )
        : null;

const isEmpty = computed(
    () =>
        props.figures.domains.total === 0 &&
        props.figures.mailboxes.total === 0 &&
        props.figures.aliases.total === 0,
);

const totalUsedMib = computed(() => usedMib(props.figures.quota.usedBytes));
</script>

<template>
    <Head title="Dashboard" />

    <div class="me-stack">
        <div v-if="isEmpty" class="me-empty">
            <p>No domains are visible to you, so there is nothing to total.</p>
        </div>

        <!--
            Every headline is an all-rows count, so it matches the total the
            corresponding listing reports, and the obvious follow-up — how many
            of those are not live — is answered beside it rather than on a
            second screen (BR-19).
        -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <MeCard title="Domains">
                <p class="text-3xl font-semibold">
                    {{ figures.domains.total.toLocaleString() }}
                </p>
                <p class="me-hint">
                    {{ figures.domains.inactive.toLocaleString() }} inactive or
                    expired
                </p>
            </MeCard>

            <MeCard title="Mailboxes">
                <p class="text-3xl font-semibold">
                    {{ figures.mailboxes.total.toLocaleString() }}
                </p>
                <p class="me-hint">
                    {{ figures.mailboxes.inactive.toLocaleString() }} inactive
                    or expired
                </p>
            </MeCard>

            <MeCard title="Standalone aliases">
                <p class="text-3xl font-semibold">
                    {{ figures.aliases.total.toLocaleString() }}
                </p>
                <p class="me-hint">
                    {{ figures.aliases.inactive.toLocaleString() }} inactive or
                    expired
                </p>
            </MeCard>

            <MeCard title="Quota allocated">
                <p class="text-3xl font-semibold">
                    {{ mib(figures.quota.allocatedMib) }}
                </p>
                <p class="me-hint">{{ mib(totalUsedMib) }} in use</p>
            </MeCard>
        </div>

        <MeCard
            title="Quota per domain"
            description="Allocation is in mebibytes and usage is converted from
                the bytes Dovecot records. Usage is correlated account by
                account through used_quota.username."
        >
            <div v-if="!figures.perDomain.length" class="me-empty">
                <p>No mailboxes in scope.</p>
            </div>

            <table v-else class="me-table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th class="me-table__cell--numeric">Mailboxes</th>
                        <th class="me-table__cell--numeric">Allocated</th>
                        <th class="me-table__cell--numeric">Used</th>
                        <th>Usage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in figures.perDomain" :key="row.domain">
                        <td>
                            <strong>{{ row.domain }}</strong>
                        </td>
                        <td class="me-table__cell--numeric">
                            {{ row.mailboxes.toLocaleString() }}
                        </td>
                        <td class="me-table__cell--numeric">
                            {{
                                row.allocatedMib === 0
                                    ? "∞"
                                    : mib(row.allocatedMib)
                            }}
                        </td>
                        <td class="me-table__cell--numeric">
                            {{ mib(usedMib(row.usedBytes)) }}
                        </td>
                        <td>
                            <MeProgress
                                v-if="usagePercent(row) !== null"
                                :value="usagePercent(row) ?? 0"
                                :variant="
                                    (usagePercent(row) ?? 0) >= 90
                                        ? 'danger'
                                        : 'primary'
                                "
                                show-value
                            />
                            <span v-else class="me-hint">Unlimited</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </MeCard>

        <MeCard
            title="Domains at their limit"
            description="A limit of 0 means unlimited, not “none allowed”, so a
                domain set to zero never appears here. The alias limit counts
                standalone alias accounts only."
        >
            <div v-if="!figures.atLimit.length" class="me-empty">
                <p>No domain has reached a limit.</p>
            </div>

            <table v-else class="me-table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th class="me-table__cell--numeric">Mailboxes</th>
                        <th class="me-table__cell--numeric">Aliases</th>
                        <th>Reached</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in figures.atLimit" :key="row.domain">
                        <td>
                            <strong>{{ row.domain }}</strong>
                        </td>
                        <td class="me-table__cell--numeric">
                            {{ row.mailboxes.count }} /
                            {{ limit(row.mailboxes.limit) }}
                        </td>
                        <td class="me-table__cell--numeric">
                            {{ row.aliases.count }} /
                            {{ limit(row.aliases.limit) }}
                        </td>
                        <td>
                            <MeBadge
                                v-for="reason in row.reasons"
                                :key="reason"
                                variant="warning"
                            >
                                {{ reason }}
                            </MeBadge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </MeCard>

        <MeCard
            title="Dormant accounts"
            description="Last login is the greater of IMAP and POP3. LDA is
                excluded: it records a delivery into the account, not a person
                reading it."
        >
            <div class="me-stack me-stack--tight">
                <!--
                    Zero and "not computable" are different values on this
                    screen, so an unreliable timestamp degrades this figure
                    rather than being counted as a recent login (BR-12).
                -->
                <MeAlert v-if="figures.dormancy.degraded" variant="warning">
                    {{ figures.dormancy.unknown.toLocaleString() }} account(s)
                    hold a last-login timestamp that is negative, zero or in the
                    future. These columns are 32-bit on MySQL and overflow in
                    2038, so those values are reported unknown and are counted
                    in neither figure below.
                </MeAlert>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-3xl font-semibold">
                            {{ figures.dormancy.dormant.toLocaleString() }}
                        </p>
                        <p class="me-hint">
                            Dormant — no IMAP or POP3 login in the last
                            {{ figures.dormancy.thresholdDays }} days
                        </p>
                    </div>
                    <div>
                        <p class="text-3xl font-semibold">
                            {{
                                figures.dormancy.neverLoggedIn.toLocaleString()
                            }}
                        </p>
                        <p class="me-hint">
                            Never logged in — no login of either kind was ever
                            recorded
                        </p>
                    </div>
                    <div>
                        <p class="text-3xl font-semibold">
                            {{ figures.dormancy.unknown.toLocaleString() }}
                        </p>
                        <p class="me-hint">
                            Unknown — the recorded timestamp cannot be trusted
                        </p>
                    </div>
                </div>

                <p class="me-hint">
                    Threshold in force: {{ figures.dormancy.thresholdDays }}
                    days.
                </p>
            </div>
        </MeCard>

        <p v-if="can.viewAuditLog" class="me-hint">
            <Link href="/audit-log">Open the audit log</Link>
        </p>
    </div>
</template>
