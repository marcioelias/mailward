<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";
import { MeAlert, MeButton, MeField, MeInput } from "@my-eyes/vue";

defineProps<{ status?: string }>();

const form = useForm({
    email: "",
    password: "",
});

const submit = (): void => {
    form.post("/login", {
        onFinish: () => form.reset("password"),
    });
};
</script>

<template>
    <Head title="Sign in" />

    <div class="me-auth">
        <header class="me-stack me-stack--tight">
            <h1>Mailward</h1>
            <p class="me-hint">Sign in with your mail account.</p>
        </header>

        <MeAlert v-if="status" variant="success">{{ status }}</MeAlert>

        <!--
            One message for every denial. The server does not distinguish an
            unknown address from a wrong password, an inactive account or a
            non-administrator, so neither does this.
        -->
        <MeAlert v-if="form.errors.email" variant="danger">
            {{ form.errors.email }}
        </MeAlert>

        <form class="me-stack" @submit.prevent="submit">
            <MeField label="Email address" for="email" required>
                <MeInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    autocomplete="username"
                    required
                    autofocus
                    :invalid="Boolean(form.errors.email)"
                />
            </MeField>

            <MeField
                label="Password"
                for="password"
                required
                hint="This is your mail password — the same one your mail client uses."
            >
                <MeInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                />
            </MeField>

            <MeButton
                type="submit"
                variant="primary"
                block
                :disabled="form.processing"
            >
                {{ form.processing ? "Signing in…" : "Sign in" }}
            </MeButton>
        </form>
    </div>
</template>
