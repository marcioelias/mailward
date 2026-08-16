<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { MeAlert, MeButton, MeCard, MeField, MeInput, MeSelect, MeSwitch } from '@my-eyes/vue'
import { computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'

interface AliasDomainForm {
    alias_domain: string
    target_domain: string
    active: boolean
}

const props = defineProps<{ aliasDomain: AliasDomainForm | null; targets: { value: string; label: string }[] }>()

const editing = computed(() => props.aliasDomain !== null)

const form = useForm({
    alias_domain: props.aliasDomain?.alias_domain ?? '',
    target_domain: props.aliasDomain?.target_domain ?? '',
    active: props.aliasDomain?.active ?? true,
})

const submit = (): void => {
    if (editing.value) {
        form.put(`/alias-domains/${props.aliasDomain?.alias_domain}`)

        return
    }

    form.post('/alias-domains')
}
</script>

<template>
    <Head :title="editing ? `Edit ${aliasDomain?.alias_domain}` : 'Add alias domain'" />

    <AppLayout :title="editing ? aliasDomain?.alias_domain : 'Add alias domain'">
        <form class="me-stack" @submit.prevent="submit">
            <MeAlert v-if="form.hasErrors" variant="danger">Some fields need attention.</MeAlert>

            <MeCard>
                <div class="me-stack">
                    <MeField
                        label="Alias domain"
                        for="alias_domain"
                        required
                        :error="form.errors.alias_domain"
                        :hint="
                            editing
                                ? 'The name is the primary key and cannot be changed. Delete and recreate to rename.'
                                : 'Mail addressed here is delivered to the accounts of the target domain.'
                        "
                    >
                        <MeInput
                            id="alias_domain"
                            v-model="form.alias_domain"
                            :disabled="editing"
                            :invalid="Boolean(form.errors.alias_domain)"
                            placeholder="example.net"
                            required
                        />
                    </MeField>

                    <MeField
                        label="Delivers to"
                        for="target_domain"
                        required
                        :error="form.errors.target_domain"
                        hint="Only domains that exist on this server, and that you administer."
                    >
                        <MeSelect
                            id="target_domain"
                            v-model="form.target_domain"
                            :options="targets"
                            placeholder="Choose a domain"
                            required
                        />
                    </MeField>

                    <MeField label="Active" for="active" :error="form.errors.active">
                        <MeSwitch id="active" v-model="form.active" />
                    </MeField>
                </div>
            </MeCard>

            <div class="me-row me-row--end">
                <Link href="/alias-domains" class="me-btn me-btn--ghost">Cancel</Link>

                <MeButton type="submit" variant="primary" :disabled="form.processing">
                    {{ editing ? 'Save changes' : 'Create alias domain' }}
                </MeButton>
            </div>
        </form>
    </AppLayout>
</template>
