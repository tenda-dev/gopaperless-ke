<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="workflow-stepper"
		:class="{
			'workflow-stepper--motion': optedIn,
			'workflow-stepper--still': optedIn && !motionEnabled,
		}">
		<div
			v-for="(step, index) in steps"
			:key="step.id"
			class="workflow-step-wrapper">
			<!-- ========================================= -->
			<!-- CONNECTOR -->
			<!-- ========================================= -->
			<div
				v-if="index !== steps.length - 1"
				class="workflow-step-connector"
				:class="{
					'workflow-step-connector--active':
						isConnectorActive(index),
				}" />

			<!-- ========================================= -->
			<!-- STEP -->
			<!-- ========================================= -->
			<div
				class="workflow-step"
				:class="[
					`workflow-step--${step.status}`,
					{ 'workflow-step--clickable': clickable && step.status !== 'locked' },
				]"
				:role="clickable && step.status !== 'locked' ? 'button' : undefined"
				:tabindex="clickable && step.status !== 'locked' ? 0 : undefined"
				@click="onStepClick(index)"
				@keydown.enter="onStepClick(index)"
				@keydown.space.prevent="onStepClick(index)">
				<div class="workflow-step-circle">
					<Transition
						name="gp-glyph"
						:css="motionEnabled">
						<NcIconSvgWrapper
							v-if="step.completed"
							key="check"
							:path="mdiCheck"
							:size="18" />

						<span v-else key="number">
							{{ index + 1 }}
						</span>
					</Transition>
				</div>

				<div class="workflow-step-label">
					{{ step.label }}
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import NcIconSvgWrapper
	from '@nextcloud/vue/components/NcIconSvgWrapper'

import {
	mdiCheck,
} from '@mdi/js'

import type {
	WorkflowStep,
} from '../../../composables/useWorkflowState'
import { useMotionGuard } from '../../../composables/useMotionGuard'
import type { MotionKey } from '../../../composables/useMotionGuard'

defineOptions({
	name: 'WorkflowStepper',
})

const props = withDefaults(defineProps<{
	steps: WorkflowStep[]
	/**
	 * Enables step navigation when supported by the consuming workflow.
	 * Disabled by default to preserve existing authenticated behaviour.
	 */
	clickable?: boolean
	/**
	 * Opts in to step-change motion (glyph, circle, connector fill). Omit it to
	 * keep the previous, unanimated behaviour (e.g. the public workflow). When
	 * the key changes (a different document) motion is suppressed for that
	 * render, so a document switch never animates.
	 */
	motionKey?: MotionKey
}>(), { clickable: false, motionKey: undefined })

const { optedIn, motionEnabled } = useMotionGuard(() => props.motionKey)

const emit = defineEmits<{ select: [index: number] }>()

function onStepClick(index: number): void {
	if (!props.clickable) { return }
	if (props.steps[index]?.status === 'locked') { return }
	emit('select', index)
}

function isConnectorActive(index: number): boolean {
	const currentStep = props.steps[index]
	const nextStep = props.steps[index + 1]

	return (
		currentStep?.completed
		|| nextStep?.active
	)
}
</script>

<style scoped lang="scss">
.workflow-stepper {
	position: relative;

	display: flex;
	align-items: flex-start;
	justify-content: space-between;

	padding: 8px 0 4px;
	// Keep vertical positioning context-specific. The public workflow rail
    // applies its own -14px adjustment, while the authenticated sidebar
    // relies on this component staying within NcAppSidebarTab's scroll area.
    // A shared negative margin pushed the step circles above the scroll
    // container's top boundary, where overflow:auto could not recover them
    // (scrollTop clamps at 0), leaving the labels visible but clipping the
    // circles on mobile.
}

.workflow-step-wrapper {
	position: relative;

	display: flex;
	flex: 1;
	justify-content: center;
}

.workflow-step {
	position: relative;
	z-index: 2;

	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;

	min-width: 72px;
}

.workflow-step-circle {
	display: flex;
	align-items: center;
	justify-content: center;

	width: 30px;
	height: 30px;

	border-radius: 999px;
	border: 1.5px solid var(--color-border);

	background: var(--color-main-background);

	font-size: 1rem;
	font-weight: 700;

	transition:
		background 180ms ease,
		border-color 180ms ease,
		color 180ms ease,
		transform 180ms ease,
		box-shadow 180ms ease,
		opacity 180ms ease;
}

.workflow-step-label {
	font-size: 12px;
	font-weight: 600;
	line-height: 1.2;
	text-align: center;

	color: var(--color-text-maxcontrast);

	transition:
		color 180ms ease,
		opacity 180ms ease;
}

.workflow-step--clickable {
	cursor: pointer;
	border-radius: 8px;
}

.workflow-step--clickable:hover .workflow-step-circle {
	transform: scale(1.06);
}

.workflow-step--clickable:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 3px;
}

/* =========================================
 * CONNECTOR
 * ========================================= */

.workflow-step-connector {
	position: absolute;

	top: 14px;
	left: 50%;

	width: 100%;
	height: 2px;

	background: var(--color-border);

	z-index: 1;

	transition:
		background 220ms ease,
		opacity 220ms ease;
}

.workflow-step-connector--active {
	background: var(--color-primary-element);
}

/* =========================================
 * MOTION (opt-in via motion-key)
 * ========================================= */

/*
 * Keep stepper transitions limited to visual state changes. Shared tokens
 * provide consistent timing, with fallbacks for contexts outside the sidebar.
 */

.workflow-stepper--motion {
	.workflow-step-circle {
		transition:
			background-color var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
			border-color var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
			color var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
			transform var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
			box-shadow var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
			opacity var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
	}

	.workflow-step-label {
		transition:
			color var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
			opacity var(--gp-motion-base, 180ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
	}

	/* The base line stays neutral; the active colour is a scaleX fill. */
	.workflow-step-connector {
		transition: none;
	}

	.workflow-step-connector--active {
		background: var(--color-border);
	}

	.workflow-step-connector::after {
		content: '';

		position: absolute;
		inset: 0;

		background: var(--color-primary-element);

		transform: scaleX(0);
		transform-origin: left center;

		transition: transform var(--gp-motion-slow, 260ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
	}

	.workflow-step-connector--active::after {
		transform: scaleX(1);
	}
}

/*
 * Suppress transitions while applying a different document's state so the
 * stepper updates immediately rather than animating between documents.
 */
.workflow-stepper--still {
	.workflow-step-circle,
	.workflow-step-label,
	.workflow-step-connector,
	.workflow-step-connector::after {
		transition: none;
	}
}

/* Enter the replacement glyph without delaying its mount behind a leave. */
.gp-glyph-enter-active {
	transition:
		opacity var(--gp-motion-fast, 120ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
		transform var(--gp-motion-fast, 120ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
}

.gp-glyph-enter-from {
	opacity: 0;
	transform: scale(0.8);
}

/* Remove the outgoing glyph immediately to avoid interrupted leave transitions. */
.gp-glyph-leave-active {
	display: none;
}

/* Keep reduced-motion support when the stepper is used outside the sidebar. */
@media (prefers-reduced-motion: reduce) {
	.workflow-stepper--motion {
		.workflow-step-circle,
		.workflow-step-label,
		.workflow-step-connector::after {
			transition-duration: 0.001ms;
		}
	}

	.gp-glyph-enter-active {
		transition-duration: 0.001ms;
	}
}

/* =========================================
 * CURRENT
 * ========================================= */

.workflow-step--current {
	.workflow-step-circle {
		background: #f59e0b;
		border-color: #f59e0b;
		color: white;

		box-shadow:
			0 0 0 4px rgba(245, 158, 11, 0.12);

		transform: scale(1.03);
	}

	.workflow-step-label {
		color: #a15c00;
		font-weight: 700;
	}
}

/* =========================================
 * COMPLETE
 * ========================================= */

.workflow-step--complete {
	.workflow-step-circle {
		background: var(--color-primary-element);
		border-color: var(--color-primary-element);

		color: white;

		box-shadow: none;
	}

	.workflow-step-label {
		color: var(--color-primary-element);
	}
}

/* =========================================
 * LOCKED
 * ========================================= */

.workflow-step--locked {
	.workflow-step-circle {
		opacity: 0.55;
		background: var(--color-background-hover);
	}

	.workflow-step-label {
		opacity: 0.55;
	}
}

/* =========================================
 * UPCOMING
 * ========================================= */

.workflow-step--upcoming {
	.workflow-step-circle {
		background: var(--color-main-background);
	}

	.workflow-step-label {
		opacity: 0.8;
	}
}
</style>
