<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { MeAlert, MeButton, MeCard, MeField, MeInput, MeNumeric } from '@my-eyes/vue'
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

            <MeCard>
                <div class="me-stack">
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
            </MeCard>

            <MeCard title="Limits" description="Zero means unlimited, not zero allowed.">
                <div class="me-stack">
                    <MeField label="Mailboxes" for="mailboxes" required :error="form.errors.mailboxes">
                        <MeNumeric id="mailboxes" v-model="form.mailboxes" :min="0" :decimals="0" required />
                    </MeField>

                    <MeField
                        label="Alias accounts"
                        for="aliases"
                        required
                        :error="form.errors.aliases"
                        hint="Counts standalone alias accounts only — not per-user aliases or alias domains."
                    >
                        <MeNumeric id="aliases" v-model="form.aliases" :min="0" :decimals="0" required />
                    </MeField>

                    <MeField
                        label="Mailing lists"
                        for="maillists"
                        required
                        :error="form.errors.maillists"
                        hint="Stored and editable, but not enforced — mailing lists are outside this version."
                    >
                        <MeNumeric id="maillists" v-model="form.maillists" :min="0" :decimals="0" required />
                    </MeField>

                    <MeField label="Maximum quota (bytes)" for="maxquota" required :error="form.errors.maxquota">
                        <MeNumeric id="maxquota" v-model="form.maxquota" :min="0" :decimals="0" required />
                    </MeField>
                </div>
            </MeCard>

            <div class="me-row me-row--end">
                <Link href="/domains" class="me-btn me-btn--ghost">Cancel</Link>

                <MeButton type="submit" variant="primary" :disabled="form.processing">
                    {{ editing ? 'Save changes' : 'Create domain' }}
                </MeButton>
            </div>
        </form>
    </AppLayout>
</template>
