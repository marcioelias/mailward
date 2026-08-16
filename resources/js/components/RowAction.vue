<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { MeButton, MeIcon, MeTooltip } from '@my-eyes/vue'
import { computed } from 'vue'

/**
 * One icon-only action in a table row, with its meaning in a tooltip.
 *
 * Two of the icons a row of actions needs — a pencil and a bin — are not in
 * the design system's set, so they are drawn here. They are plain `svg`
 * elements rather than `MeIcon` slots because `MeIcon` requires a `name` from
 * the shipped set: the slot escape hatch the reference describes is not
 * expressible in its types. When the package gains the two names, this
 * component keeps its shape and loses the paths.
 *
 * The tooltip is not decoration. An icon-only control is unreadable without
 * one, and a `title` attribute alone never reaches a keyboard user.
 */
type Action = 'edit' | 'delete' | 'password' | 'routing' | 'enable' | 'disable'

const props = defineProps<{
    label: string
    icon: Action
    href?: string
    variant?: 'ghost' | 'danger'
}>()

/** Actions the package already has a name for. */
const SHIPPED = {
    password: 'lock',
    routing: 'mail',
    enable: 'check-circle',
    disable: 'minus',
} as const

const shippedIcon = computed(() => SHIPPED[props.icon as keyof typeof SHIPPED])
</script>

<template>
    <MeTooltip :text="label">
        <component
            :is="href ? Link : MeButton"
            v-bind="
                href
                    ? { href, class: 'me-btn me-btn--ghost me-btn--sm me-btn--icon' }
                    : { variant: variant ?? 'ghost', size: 'sm', iconOnly: true }
            "
            :aria-label="label"
        >
            <MeIcon v-if="shippedIcon" :name="shippedIcon" />

            <svg
                v-else
                class="me-row-action__icon"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <template v-if="icon === 'edit'">
                    <path d="M12 20h9" />
                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                </template>
                <template v-else>
                    <path d="M3 6h18" />
                    <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2" />
                    <path d="M19 6v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6" />
                    <path d="M10 11v6M14 11v6" />
                </template>
            </svg>
        </component>
    </MeTooltip>
</template>

<style scoped>
/* Matches the box MeIcon renders, so a drawn icon and a shipped one line up. */
.me-row-action__icon {
    width: 1.125rem;
    height: 1.125rem;
    flex: none;
}
</style>
