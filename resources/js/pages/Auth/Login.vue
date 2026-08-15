<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

defineProps<{ status?: string }>()

const form = useForm({
    email: '',
    password: '',
})

const submit = (): void => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-full items-center justify-center px-6 py-16">
        <div class="w-full max-w-sm">
            <header class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Mailward</h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Sign in with your mail account.
                </p>
            </header>

            <div
                v-if="status"
                class="mb-6 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                {{ status }}
            </div>

            <!--
                One message for every denial. The server does not distinguish
                an unknown address from a wrong password, an inactive account
                or a non-administrator, so neither does this.
            -->
            <div
                v-if="form.errors.email"
                class="mb-6 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
            >
                {{ form.errors.email }}
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium"> Email address </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        autofocus
                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-900"
                    />
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium"> Password </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-900"
                    />
                    <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                        This is your mail password — the same one your mail client uses.
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none disabled:opacity-60"
                >
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>
        </div>
    </div>
</template>
