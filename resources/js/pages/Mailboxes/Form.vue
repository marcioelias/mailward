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
import { Head, Link, useForm } from "@inertiajs/vue3";
import {
    MeAlert,
    MeButton,
    MeCard,
    MeField,
    MeInput,
    MeNumeric,
    MeSelect,
    MeSwitch,
} from "@my-eyes/vue";
import { computed } from "vue";

interface MailboxForm {
    username: string;
    name: string | null;
    domain: string;
    quota: number;
    active: boolean;
    services: Record<string, boolean>;
    usedBytes: number;
    lastLogin: { imap: number | null; pop3: number | null };
    weakness: string | null;
}

const props = defineProps<{
    mailbox: MailboxForm | null;
    domains: { value: string; label: string }[];
    services: { key: string; label: string }[];
    allowance: { mailboxes: number | null; quotaMib: number | null } | null;
}>();

const editing = computed(() => props.mailbox !== null);

const form = useForm({
    username: props.mailbox?.username ?? "",
    password: "",
    password_confirmation: "",
    name: props.mailbox?.name ?? "",
    quota: props.mailbox?.quota ?? 0,
    active: props.mailbox?.active ?? true,
    services:
        props.mailbox?.services ??
        Object.fromEntries(props.services.map((s) => [s.key, true])),
});

const submit = (): void => {
    if (editing.value) {
        form.put(`/mailboxes/${props.mailbox?.username}`);

        return;
    }

    form.post("/mailboxes");
};

const usedMib = computed(() =>
    Math.round((props.mailbox?.usedBytes ?? 0) / 1048576),
);

const lastLogin = computed(() => {
    const times = [
        props.mailbox?.lastLogin.imap,
        props.mailbox?.lastLogin.pop3,
    ].filter((t): t is number => typeof t === "number" && t > 0);

    if (!times.length) {
        return "Never signed in";
    }

    return new Date(Math.max(...times) * 1000).toLocaleString();
});
</script>

<template>
    <Head :title="editing ? `Edit ${mailbox?.username}` : 'Add mailbox'" />
    <div class="me-stack">
        <form class="me-stack" @submit.prevent="submit">
            <MeAlert v-if="form.hasErrors" variant="danger">
                Some fields need attention.
            </MeAlert>

            <MeCard title="Account">
                <div class="me-stack">
                    <MeField
                        label="Address"
                        for="username"
                        required
                        :error="form.errors.username"
                        :hint="
                            editing
                                ? 'The address is the account key and cannot be changed.'
                                : 'The domain must already exist on this server.'
                        "
                    >
                        <MeInput
                            id="username"
                            v-model="form.username"
                            type="email"
                            :disabled="editing"
                            :invalid="Boolean(form.errors.username)"
                            placeholder="name@example.com"
                            required
                        />
                    </MeField>

                    <MeField
                        label="Display name"
                        for="name"
                        :error="form.errors.name"
                    >
                        <MeInput id="name" v-model="form.name" />
                    </MeField>

                    <template v-if="!editing">
                        <MeField
                            label="Mail password"
                            for="password"
                            required
                            :error="form.errors.password"
                            hint="This is the account's real mail password — it works for IMAP, SMTP and webmail."
                        >
                            <MeInput
                                id="password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                        </MeField>

                        <MeField
                            label="Repeat the password"
                            for="password_confirmation"
                            required
                        >
                            <MeInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                        </MeField>
                    </template>

                    <MeField label="Active" for="active">
                        <MeSwitch id="active" v-model="form.active" />
                    </MeField>
                </div>
            </MeCard>

            <MeCard
                title="Quota"
                :description="
                    allowance?.quotaMib === null || allowance === null
                        ? 'Zero means unlimited.'
                        : `Zero means unlimited. ${allowance.quotaMib} MiB remain in this domain's pool.`
                "
            >
                <div class="me-stack">
                    <MeField
                        label="Quota (MiB)"
                        for="quota"
                        required
                        :error="form.errors.quota"
                    >
                        <MeNumeric
                            id="quota"
                            v-model="form.quota"
                            :min="0"
                            :decimals="0"
                            required
                        />
                    </MeField>

                    <p v-if="editing" class="me-hint">
                        Currently using {{ usedMib }} MiB. Last sign-in:
                        {{ lastLogin }}.
                    </p>
                </div>
            </MeCard>

            <MeCard
                title="Services"
                description="Which protocols this account may use. Dovecot's internal toggles are deliberately not exposed."
            >
                <div class="me-stack me-stack--tight">
                    <MeField
                        v-for="service in services"
                        :key="service.key"
                        :label="service.label"
                        :for="service.key"
                        inline
                    >
                        <MeSwitch
                            :id="service.key"
                            v-model="form.services[service.key]"
                        />
                    </MeField>
                </div>
            </MeCard>

            <div class="me-row me-row--end">
                <Link href="/mailboxes" class="me-btn me-btn--ghost"
                    >Cancel</Link
                >

                <MeButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                >
                    {{ editing ? "Save changes" : "Create mailbox" }}
                </MeButton>
            </div>
        </form>

        <MeCard
            v-if="editing"
            title="Mail password"
            description="Changing it takes effect on IMAP, SMTP and webmail at once, so it has a screen of its own."
        >
            <MeAlert v-if="mailbox?.weakness" variant="warning">
                {{ mailbox.weakness }}
            </MeAlert>

            <Link
                :href="`/mailboxes/${mailbox?.username}/password`"
                class="me-btn me-btn--secondary"
            >
                Change the mail password
            </Link>
        </MeCard>
    </div>
</template>
