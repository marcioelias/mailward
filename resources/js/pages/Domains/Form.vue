<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { MeAlert, MeButton, MeField, MeInput } from '@my-eyes/vue'
import { computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'

interface DomainForm {
    domain: string
    description: string | null
    disclaimer: string | null
    aliases: number
    mailboxes: number
    maillists: number
    maxquota: number
    backupmx: boolean
}

const props = defineProps<{ domain: DomainForm | null }>()

const editing = computed(() => props.domain !== null)

const form = useForm({
    domain: props.domain?.domain ?? '',
    description: props.domain?.description ?? '',
    disclaimer: props.domain?.disclaimer ?? '',
    aliases: props.domain?.aliases ?? 0,
    mailboxes: props.domain?.mailboxes ?? 0,
    maillists: props.domain?.maillists ?? 0,
    maxquota: props.domain?.maxquota ?? 0,
    backupmx: props.domain?.backupmx ?? false,
})

const submit = (): void => {
    if (editing.value) {
        form.put(`/domains/${props.domain?.domain}`)

        return
    }

    form.post('/domains')
}
</script>

<template>
    <Head :title="editing ? `Edit ${domain?.domain}` : 'Add domain'" />

    <AppLayout :title="editing ? domain?.domain : 'Add domain'">
        <form class="me-stack" @submit.prevent="submit">
            <MeAlert v-if="form.hasErrors" variant="danger">
                Some fields need attention.
            </MeAlert>

            <div class="me-card">
                <div class="me-card__body me-stack">
                    <MeField
                        label="Domain name"
                        for="domain"
                        required
                        :error="form.errors.domain"
                        :hint="editing ? 'The name is the primary key and cannot be changed here.' : ''"
                    >
                        <MeInput
                            id="domain"
                            v-model="form.domain"
                            :disabled="editing"
                            :invalid="Boolean(form.errors.domain)"
                            placeholder="example.com"
                            required
                        />
                    </MeField>

                    <MeField label="Description" for="description" :error="form.errors.description">
                        <MeInput id="description" v-model="form.description" />
                    </MeField>
                </div>
            </div>

            <div class="me-card">
                <div class="me-card__header">
                    <h2 class="me-card__title">Limits</h2>
                    <p class="me-card__description">Zero means unlimited, not zero allowed.</p>
                </div>

                <div class="me-card__body me-stack">
                    <MeField label="Mailboxes" for="mailboxes" required :error="form.errors.mailboxes">
                        <MeInput id="mailboxes" v-model="form.mailboxes" type="number" min="0" required />
                    </MeField>

                    <MeField
                        label="Alias accounts"
                        for="aliases"
                        required
                        :error="form.errors.aliases"
                        hint="Counts standalone alias accounts only — not per-user aliases or alias domains."
                    >
                        <MeInput id="aliases" v-model="form.aliases" type="number" min="0" required />
                    </MeField>

                    <MeField
                        label="Mailing lists"
                        for="maillists"
                        required
                        :error="form.errors.maillists"
                        hint="Stored and editable, but not enforced — mailing lists are outside this version."
                    >
                        <MeInput id="maillists" v-model="form.maillists" type="number" min="0" required />
                    </MeField>

                    <MeField label="Maximum quota (bytes)" for="maxquota" required :error="form.errors.maxquota">
                        <MeInput id="maxquota" v-model="form.maxquota" type="number" min="0" required />
                    </MeField>
                </div>
            </div>

            <div class="me-row me-row--end">
                <Link href="/domains" class="me-btn me-btn--ghost">Cancel</Link>

                <MeButton type="submit" variant="primary" :disabled="form.processing">
                    {{ editing ? 'Save changes' : 'Create domain' }}
                </MeButton>
            </div>
        </form>
    </AppLayout>
</template>
