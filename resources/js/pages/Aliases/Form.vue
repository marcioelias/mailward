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
    MeButton,
    MeCard,
    MeField,
    MeInput,
    MeSwitch,
} from "@my-eyes/vue";
import { computed } from "vue";

interface AliasForm {
    address: string;
    name: string | null;
    accesspolicy: string | null;
    domain: string;
    active: boolean;
}

const props = defineProps<{
    alias: AliasForm | null;
    /*
     * Member addresses only. A member row is present or absent and nothing
     * else: `forwardings.active` is always written as 1 and this screen offers
     * no transition on it, because nothing sourced says any iRedMail component
     * reads it on an is_list row (BR-21).
     */
    members: string[];
    domains: { value: string; label: string }[];
}>();

const editing = computed(() => props.alias !== null);

const form = useForm({
    address: props.alias?.address ?? "",
    name: props.alias?.name ?? "",
    accesspolicy: props.alias?.accesspolicy ?? "",
    active: props.alias?.active ?? true,
});

const submit = (): void => {
    if (editing.value) {
        form.put(`/aliases/${props.alias?.address}`);

        return;
    }

    form.post("/aliases");
};

/*
 * Members are added and removed one at a time, against their own endpoints:
 * the alias row and the member rows are two different tables, and the create
 * of an alias is deliberately a single-table write (BR-01, BR-02, BR-20).
 */
const member = useForm({ forwarding: "" });

const addMember = (): void => {
    member.post(`/aliases/${props.alias?.address}/members`, {
        preserveScroll: true,
        onSuccess: () => member.reset(),
    });
};

const removeMember = (forwarding: string): void => {
    router.delete(
        `/aliases/${props.alias?.address}/members/${encodeURIComponent(forwarding)}`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="editing ? `Edit ${alias?.address}` : 'Add alias'" />
    <div class="me-stack">
        <form class="me-stack" @submit.prevent="submit">
            <MeAlert v-if="form.hasErrors" variant="danger">
                Some fields need attention.
            </MeAlert>

            <MeCard title="Alias">
                <div class="me-stack">
                    <MeField
                        label="Address"
                        for="address"
                        required
                        :error="form.errors.address"
                        :hint="
                            editing
                                ? 'The address is the alias key and cannot be changed. Delete and recreate to rename.'
                                : 'The domain must already exist on this server — an alias domain does not count.'
                        "
                    >
                        <MeInput
                            id="address"
                            v-model="form.address"
                            type="email"
                            :disabled="editing"
                            :invalid="Boolean(form.errors.address)"
                            placeholder="sales@example.com"
                            required
                        />
                    </MeField>

                    <MeField
                        label="Label"
                        for="name"
                        :error="form.errors.name"
                        hint="Shown in listings only. It is not part of the address."
                    >
                        <MeInput id="name" v-model="form.name" />
                    </MeField>

                    <MeField
                        label="Access policy"
                        for="accesspolicy"
                        :error="form.errors.accesspolicy"
                        hint="Free text, passed through to iRedMail unchanged. Leave it empty unless you know the value your server honours."
                    >
                        <MeInput
                            id="accesspolicy"
                            v-model="form.accesspolicy"
                            maxlength="30"
                        />
                    </MeField>

                    <MeField
                        label="Active"
                        for="active"
                        :error="form.errors.active"
                    >
                        <MeSwitch id="active" v-model="form.active" />
                    </MeField>
                </div>
            </MeCard>

            <div class="me-row me-row--end">
                <Link href="/aliases" class="me-btn me-btn--ghost">Cancel</Link>

                <MeButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                >
                    {{ editing ? "Save changes" : "Create alias" }}
                </MeButton>
            </div>
        </form>

        <MeCard v-if="editing" title="Members">
            <div class="me-stack">
                <!-- The black hole is visible rather than impossible (BR-20). -->
                <MeAlert v-if="!members.length" variant="warning">
                    This alias has 0 members: it accepts mail and delivers it
                    nowhere. Add a member below.
                </MeAlert>

                <table v-if="members.length" class="me-table">
                    <thead>
                        <tr>
                            <th>Delivers to</th>
                            <th class="me-table__cell--end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in members" :key="row">
                            <td>{{ row }}</td>
                            <td class="me-table__cell--end">
                                <MeButton
                                    variant="ghost"
                                    size="sm"
                                    @click="removeMember(row)"
                                >
                                    Remove
                                </MeButton>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form class="me-row" @submit.prevent="addMember">
                    <MeField
                        label="Add a member"
                        for="forwarding"
                        :error="member.errors.forwarding"
                        hint="Any address. One inside a domain this server hosts must already exist; an address elsewhere is accepted as typed."
                    >
                        <MeInput
                            id="forwarding"
                            v-model="member.forwarding"
                            type="email"
                            :invalid="Boolean(member.errors.forwarding)"
                            placeholder="someone@example.com"
                            required
                        />
                    </MeField>

                    <MeButton
                        type="submit"
                        variant="secondary"
                        :disabled="member.processing"
                    >
                        Add member
                    </MeButton>
                </form>
            </div>
        </MeCard>
    </div>
</template>
