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
import { Head, useForm, usePage } from "@inertiajs/vue3";
import {
    MeAlert,
    MeBadge,
    MeButton,
    MeCard,
    MeField,
    MeInput,
} from "@my-eyes/vue";
import { computed, onMounted, ref } from "vue";
import { suggestPassword } from "@/support/suggestPassword";

const props = defineProps<{
    account: {
        username: string;
        name: string | null;
        domain: string;
        isglobaladmin: boolean;
        weakness: string | null;
    };
}>();

const page = usePage();
const status = computed(() => (page.props as { status?: string }).status);

const profile = useForm({ name: props.account.name ?? "" });

const password = useForm({ password: "", password_confirmation: "" });

const suggestion = ref("");
const copied = ref(false);

const rotate = (): void => {
    suggestion.value = suggestPassword();
    copied.value = false;
};

onMounted(rotate);

const use = async (): Promise<void> => {
    password.password = suggestion.value;
    password.password_confirmation = suggestion.value;

    try {
        await navigator.clipboard.writeText(suggestion.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <Head title="Your account" />
    <div class="me-stack">
        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <MeCard title="Profile">
            <form class="me-stack" @submit.prevent="profile.put('/account')">
                <MeField
                    label="Display name"
                    for="name"
                    :error="profile.errors.name"
                    hint="What recipients see beside your address."
                >
                    <MeInput id="name" v-model="profile.name" />
                </MeField>

                <p class="me-hint">
                    {{ account.username }}
                    <MeBadge v-if="account.isglobaladmin" variant="info"
                        >Global admin</MeBadge
                    >
                </p>

                <!--
                        Quota, services and the administrator flags are
                        deliberately absent: they are decisions about an
                        account, and making them about yourself is the
                        self-escalation BR-A02 forbids.
                    -->
                <div class="me-row me-row--end">
                    <MeButton
                        type="submit"
                        variant="primary"
                        :disabled="profile.processing"
                    >
                        Save
                    </MeButton>
                </div>
            </form>
        </MeCard>

        <MeCard
            title="Your mail password"
            description="The same password your mail client uses. Changing it signs you out of every mail client until you enter the new one."
        >
            <div class="me-stack">
                <MeAlert v-if="account.weakness" variant="warning">
                    {{ account.weakness }}
                </MeAlert>

                <p class="me-suggestion">{{ suggestion }}</p>

                <div class="me-row">
                    <MeButton variant="secondary" icon="check" @click="use">
                        {{ copied ? "Copied and filled in" : "Use this one" }}
                    </MeButton>
                    <MeButton variant="ghost" @click="rotate"
                        >Suggest another</MeButton
                    >
                </div>

                <form
                    class="me-stack"
                    @submit.prevent="
                        password.put('/account/password', {
                            onSuccess: () => password.reset(),
                        })
                    "
                >
                    <MeField
                        label="New password"
                        for="password"
                        required
                        :error="password.errors.password"
                        hint="At least twelve characters."
                    >
                        <MeInput
                            id="password"
                            v-model="password.password"
                            type="password"
                            autocomplete="new-password"
                            :invalid="Boolean(password.errors.password)"
                            required
                        />
                    </MeField>

                    <MeField
                        label="Repeat it"
                        for="password_confirmation"
                        required
                    >
                        <MeInput
                            id="password_confirmation"
                            v-model="password.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </MeField>

                    <div class="me-row me-row--end">
                        <MeButton
                            type="submit"
                            variant="danger"
                            :disabled="password.processing"
                        >
                            Change my mail password
                        </MeButton>
                    </div>
                </form>
            </div>
        </MeCard>
    </div>
</template>

<style scoped>
.me-suggestion {
    font-family: var(--me-font-mono, ui-monospace, monospace);
    font-size: 1.35rem;
    letter-spacing: 0.06em;
    margin: 0;
    user-select: all;
    word-break: break-all;
}
</style>
