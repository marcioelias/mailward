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
import { Head, Link, router, setLayoutProps } from "@inertiajs/vue3";
import { MeBadge, MeCard, MeSelect } from "@my-eyes/vue";
import { ref, watch } from "vue";

interface Change {
    field: string;
    from: string | null;
    to: string | null;
}

interface EntryRow {
    id: number;
    occurredAt: string | null;
    actor: string | null;
    event: string | null;
    description: string;
    target: { type: string | null; id: string | null };
    ip: string | null;
    changes: Change[];
    removed: Record<string, number>;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    entries: Paginated<EntryRow>;
    filters: { actor: string; event: string };
    actors: Option[];
    events: Option[];
}>();

setLayoutProps({
    title: "Audit log",
    subtitle: "Every write Mailward performed, and who performed it.",
});

const actor = ref(props.filters.actor);
const event = ref(props.filters.event);

watch([actor, event], () => {
    router.get(
        "/audit-log",
        { actor: actor.value, event: event.value },
        { preserveState: true, replace: true },
    );
});

/*
 * The log is written in UTC and read by a person, so the timestamp is rendered
 * in the reader's own locale rather than left as an ISO string.
 */
const when = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : "—";

const removedEntries = (row: EntryRow): [string, number][] =>
    Object.entries(row.removed);
</script>

<template>
    <Head title="Audit log" />

    <div class="me-stack">
        <!--
            Read-only, and there is no control here that is not a filter: no
            edit, no delete, no export. An entry is answered by a later entry,
            never by changing the first (docs/features/audit-log.md BR-05).
        -->
        <div class="me-row">
            <MeSelect
                v-model="actor"
                :options="actors"
                placeholder="Any administrator"
                clearable
            />
            <MeSelect
                v-model="event"
                :options="events"
                placeholder="Any event"
                clearable
            />
        </div>

        <div v-if="!entries.data.length" class="me-empty">
            <p v-if="filters.actor || filters.event">
                No entry matches that filter.
            </p>
            <p v-else>Nothing has been recorded yet.</p>
        </div>

        <div v-else class="me-stack">
            <MeCard v-for="row in entries.data" :key="row.id" flush>
                <div class="me-stack me-stack--tight p-4">
                    <div class="me-row me-row--between">
                        <div>
                            <MeBadge variant="info">{{
                                row.event ?? "unknown"
                            }}</MeBadge>
                            <strong>{{ row.target.type ?? "—" }}</strong>
                            <span v-if="row.target.id">
                                {{ row.target.id }}</span
                            >
                        </div>
                        <span class="me-hint">{{ when(row.occurredAt) }}</span>
                    </div>

                    <p class="me-hint">
                        <!--
                        The actor is stored denormalised, so an entry naming an
                        account that no longer exists still reads (BR-08). The
                        console sentinel is never a real address, so a console
                        run is told from a web action by the actor alone
                        (BR-20).
                    -->
                        {{ row.actor ?? "unknown" }} —
                        {{ row.ip ?? "no origin" }}
                    </p>

                    <table
                        v-if="row.changes.length"
                        class="me-table me-table--compact"
                    >
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Before</th>
                                <th>After</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="change in row.changes"
                                :key="change.field"
                            >
                                <td>{{ change.field }}</td>
                                <td>{{ change.from ?? "—" }}</td>
                                <td>{{ change.to ?? "—" }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <!--
                    A cascade is one entry for the whole operation, and its
                    payload names what it removed per table rather than merely
                    that it removed something (BR-19).
                -->
                    <p v-if="removedEntries(row).length" class="me-row">
                        <MeBadge
                            v-for="[table, count] in removedEntries(row)"
                            :key="table"
                            variant="warning"
                        >
                            {{ count }} {{ table }} removed
                        </MeBadge>
                    </p>
                </div>
            </MeCard>
        </div>

        <nav v-if="entries.links.length > 3" class="me-pagination">
            <Link
                v-for="link in entries.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="me-pagination__item"
                :aria-current="link.active ? 'page' : undefined"
                v-html="link.label"
            />
        </nav>
    </div>
</template>
