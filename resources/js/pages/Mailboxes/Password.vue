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
import { MeAlert, MeButton, MeCard, MeField, MeInput } from "@my-eyes/vue";
import { onMounted, ref } from "vue";
import { suggestPassword } from "@/support/suggestPassword";

const props = defineProps<{
    mailbox: { username: string; name: string | null; weakness: string | null };
}>();

const form = useForm({ password: "", password_confirmation: "" });

const suggestion = ref("");
const copied = ref(false);

const rotate = (): void => {
    suggestion.value = suggestPassword();
    copied.value = false;
};

onMounted(rotate);

const use = async (): Promise<void> => {
    form.password = suggestion.value;
    form.password_confirmation = suggestion.value;

    try {
        await navigator.clipboard.writeText(suggestion.value);
        copied.value = true;
    } catch {
        // Clipboard access can be refused. The value is already in both fields
        // and visible on screen, so this is a convenience that failed, not the
        // operation failing.
        copied.value = false;
    }
};

const submit = (): void => {
    form.put(`/mailboxes/${props.mailbox.username}/password`, {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head :title="`Password — ${mailbox.username}`" />
    <div class="me-stack">
        <!--
                Stated before the button rather than after it. This is not a
                panel credential (docs/02-domain.md §4, BR-36).
            -->
        <MeAlert variant="warning">
            This is the account's real mail password. Saving changes IMAP, SMTP
            and webmail at the same moment, and signs the person out of every
            mail client they use until they enter the new one.
        </MeAlert>

        <MeAlert v-if="mailbox.weakness" variant="info">
            The password stored today is weaker than the one Mailward writes:
            {{ mailbox.weakness }} The account works — this is worth changing,
            not urgent.
        </MeAlert>

        <MeCard
            title="Suggested password"
            description="Generated in your browser. Nothing is sent until you save."
        >
            <div class="me-stack">
                <p class="me-suggestion">{{ suggestion }}</p>

                <p class="me-hint">
                    Sixteen characters, grouped so it survives being read aloud.
                    No
                    <code>0</code>/<code>O</code> or
                    <code>1</code>/<code>l</code>/<code>I</code>, which is what
                    gets mistyped on a phone.
                </p>

                <div class="me-row">
                    <MeButton variant="primary" icon="check" @click="use">
                        {{ copied ? "Copied and filled in" : "Use this one" }}
                    </MeButton>

                    <MeButton variant="secondary" @click="rotate"
                        >Suggest another</MeButton
                    >
                </div>
            </div>
        </MeCard>

        <form @submit.prevent="submit">
            <MeCard title="New password">
                <div class="me-stack">
                    <MeAlert v-if="form.errors.password" variant="danger">
                        {{ form.errors.password }}
                    </MeAlert>

                    <MeField
                        label="New password"
                        for="password"
                        required
                        :error="form.errors.password"
                        hint="At least twelve characters."
                    >
                        <MeInput
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            :invalid="Boolean(form.errors.password)"
                            required
                        />
                    </MeField>

                    <MeField
                        label="Repeat it"
                        for="password_confirmation"
                        required
                        :error="form.errors.password_confirmation"
                    >
                        <MeInput
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </MeField>

                    <div class="me-row me-row--between">
                        <Link href="/mailboxes" class="me-btn me-btn--ghost"
                            >Cancel</Link
                        >

                        <MeButton
                            type="submit"
                            variant="danger"
                            :disabled="form.processing"
                        >
                            Change the mail password
                        </MeButton>
                    </div>
                </div>
            </MeCard>
        </form>
    </div>
</template>

<style scoped>
.me-suggestion {
    font-family: var(--me-font-mono, ui-monospace, monospace);
    font-size: 1.5rem;
    letter-spacing: 0.06em;
    margin: 0;
    user-select: all;
    word-break: break-all;
}
</style>
