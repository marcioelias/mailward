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
import { Head, Link, router, setLayoutProps, useForm } from "@inertiajs/vue3";
import {
    MeAlert,
    MeBadge,
    MeButton,
    MeCard,
    MeField,
    MeSelect,
} from "@my-eyes/vue";
import { computed, ref } from "vue";

interface Administrator {
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

const props = defineProps<{
    administrator: Administrator | null;
    domains: { value: string; label: string }[];
    candidates: { value: string; label: string }[];
    status?: string;
}>();

const editing = computed(() => props.administrator !== null);

setLayoutProps({
    title: editing.value ? "Manage administrator" : "Promote an account",
    subtitle: editing.value
        ? props.administrator?.address
        : "Promotion targets a mail account that already exists.",
});

const encode = (address: string): string => encodeURIComponent(address);

/* Creating: pick an existing mailbox and, optionally, its initial domains. */
const form = useForm<{ username: string; domains: string[] }>({
    username: "",
    domains: [],
});

const initialDomain = ref("");

const addInitialDomain = (): void => {
    const domain = initialDomain.value;

    if (domain && !form.domains.includes(domain)) {
        form.domains.push(domain);
    }

    initialDomain.value = "";
};

const removeInitialDomain = (domain: string): void => {
    form.domains = form.domains.filter((each) => each !== domain);
};

const submit = (): void => {
    form.post("/admins");
};

/* Editing: each change is its own audited request. */
const newDomain = ref("");

const assignDomain = (): void => {
    const address = props.administrator?.address;

    if (!address || !newDomain.value) {
        return;
    }

    router.post(
        `/admins/${encode(address)}/domains`,
        { domain: newDomain.value },
        { preserveScroll: true, onSuccess: () => (newDomain.value = "") },
    );
};

const removeDomain = (domain: string): void => {
    const address = props.administrator?.address;

    if (address) {
        router.delete(
            `/admins/${encode(address)}/domains/${encodeURIComponent(domain)}`,
            { preserveScroll: true },
        );
    }
};

const setGlobal = (grant: boolean): void => {
    const address = props.administrator?.address;

    if (!address) {
        return;
    }

    if (grant) {
        router.post(
            `/admins/${encode(address)}/global`,
            {},
            { preserveScroll: true },
        );

        return;
    }

    router.delete(`/admins/${encode(address)}/global`, {
        preserveScroll: true,
    });
};

/* Domains not already assigned, so the picker cannot offer a no-op. */
const assignable = computed(() =>
    props.domains.filter(
        (option) =>
            !(props.administrator?.domains ?? []).includes(option.value),
    ),
);
</script>

<template>
    <Head
        :title="
            editing ? `Manage ${administrator?.address}` : 'Promote an account'
        "
    />

    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <!--
            BR-A01 and BR-A02 are refused on the server and come back as an
            error on `global`. The buttons are hidden when the server already
            knows the answer, but a hidden button is not the boundary.
        -->
        <MeAlert v-if="form.errors.username" variant="danger">
            {{ form.errors.username }}
        </MeAlert>

        <template v-if="!editing">
            <form class="me-stack" @submit.prevent="submit">
                <MeCard>
                    <div class="me-stack">
                        <MeField
                            label="Mail account"
                            for="username"
                            required
                            :error="form.errors.username"
                            hint="Promotion never creates an account. Only existing mailboxes appear here."
                        >
                            <MeSelect
                                id="username"
                                v-model="form.username"
                                :options="candidates"
                                placeholder="Choose a mail account"
                                required
                            />
                        </MeField>

                        <MeField
                            label="Initial domains"
                            for="initial-domain"
                            :error="form.errors.domains"
                            hint="Optional. Domains can be assigned and removed afterwards."
                        >
                            <div class="me-row">
                                <MeSelect
                                    id="initial-domain"
                                    v-model="initialDomain"
                                    :options="domains"
                                    placeholder="Choose a domain"
                                    clearable
                                />
                                <MeButton
                                    variant="ghost"
                                    @click="addInitialDomain"
                                >
                                    Add
                                </MeButton>
                            </div>
                        </MeField>

                        <div v-if="form.domains.length" class="me-row">
                            <MeBadge
                                v-for="domain in form.domains"
                                :key="domain"
                                variant="info"
                            >
                                {{ domain }}
                                <button
                                    type="button"
                                    @click="removeInitialDomain(domain)"
                                >
                                    ×
                                </button>
                            </MeBadge>
                        </div>
                    </div>
                </MeCard>

                <div class="me-row me-row--end">
                    <Link href="/admins" class="me-btn me-btn--ghost">
                        Cancel
                    </Link>

                    <MeButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                    >
                        Promote
                    </MeButton>
                </div>
            </form>
        </template>

        <template v-else>
            <MeCard>
                <div class="me-stack">
                    <h2>Domains</h2>

                    <p v-if="administrator?.isglobaladmin">
                        A global administrator reaches every domain on this
                        server. The list below is informational — it comes from
                        the global flag, not from individual assignments.
                    </p>

                    <div v-if="!administrator?.domains.length" class="me-empty">
                        <p>
                            No domains assigned. The account can sign in and
                            sees an empty screen.
                        </p>
                    </div>

                    <ul v-else>
                        <li
                            v-for="domain in administrator?.domains"
                            :key="domain"
                        >
                            {{ domain }}
                            <MeButton
                                v-if="
                                    administrator?.can.manage &&
                                    !administrator?.isglobaladmin
                                "
                                variant="ghost"
                                size="sm"
                                @click="removeDomain(domain)"
                            >
                                Remove
                            </MeButton>
                        </li>
                    </ul>

                    <MeField
                        v-if="administrator?.can.manage"
                        label="Assign a domain"
                        for="new-domain"
                    >
                        <div class="me-row">
                            <MeSelect
                                id="new-domain"
                                v-model="newDomain"
                                :options="assignable"
                                placeholder="Choose a domain"
                                clearable
                            />
                            <MeButton variant="ghost" @click="assignDomain">
                                Assign
                            </MeButton>
                        </div>
                    </MeField>
                </div>
            </MeCard>

            <MeCard>
                <div class="me-stack">
                    <h2>Global administrator</h2>

                    <p>
                        A global administrator reaches every domain, and may
                        promote and demote other administrators.
                    </p>

                    <div class="me-row">
                        <MeButton
                            v-if="administrator?.can.promoteToGlobal"
                            variant="primary"
                            @click="setGlobal(true)"
                        >
                            Make global administrator
                        </MeButton>

                        <MeButton
                            v-if="administrator?.can.revokeGlobal"
                            variant="ghost"
                            @click="setGlobal(false)"
                        >
                            Revoke the global flag
                        </MeButton>

                        <!--
                            BR-A02: nobody revokes their own global flag, and
                            BR-A01 keeps the last one. Neither control is
                            offered, and both are refused again on the server.
                        -->
                        <p
                            v-if="
                                administrator?.isglobaladmin &&
                                !administrator?.can.revokeGlobal
                            "
                        >
                            This flag cannot be revoked here — either it is your
                            own account, or this is the last global
                            administrator left.
                        </p>
                    </div>
                </div>
            </MeCard>

            <div class="me-row me-row--end">
                <Link href="/admins" class="me-btn me-btn--ghost">Back</Link>
            </div>
        </template>
    </div>
</template>
