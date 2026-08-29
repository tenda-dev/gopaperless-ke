<template>
  <div class="credits-row">
    <div class="credits-avatar" aria-hidden="true">
      {{ initial }}
    </div>

    <div class="credits-identity">
      <span class="credits-email">{{ email }}</span>
		<span class="credits-meta">
			<span class="credits-count-wrap">
				<span class="credits-count" :class="countClass">
				{{ loading ? '—' : remainingUses }}
				</span>
			</span>certified signatures
		</span>
    </div>

    <button
      class="buy-pill"
      :disabled="loading"
      @click="showPurchaseFlow = true"
    >
      Top up
    </button>

    <CreditPurchaseFlow
      v-if="showPurchaseFlow"
      :product-code="DEFAULT_PRODUCT_CODE"
	  payment-purpose="credit_purchase"
	  :allow-quantity-selection="true"
	  :presentation="purchasePresentation"
      @success="handlePurchaseSuccess"
      @close="showPurchaseFlow = false"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import CreditPurchaseFlow from '@/components/Payments/CreditPurchaseFlow.vue'
import { useEntitlementStore } from '@/store/entitlement'
import { CREDIT_PURCHASE_PRESENTATION } from '@/constants/purchasePresentation'
import { useUserContextStore } from '@/store/userContext'
import { DEFAULT_PRODUCT_CODE } from '@/constants/product'
import type { PaymentPresentation } from '@/payment'

const entitlementStore = useEntitlementStore()
const userContext = useUserContextStore()

const showPurchaseFlow = ref(false)
const countClass = ref('')
const email = computed(() => userContext.emailAddress ?? '')

const loading = entitlementStore.isLoading(DEFAULT_PRODUCT_CODE)
const entitlement = entitlementStore.getEntitlement(DEFAULT_PRODUCT_CODE)

const remainingUses = computed(() => entitlement.value?.remainingUses ?? 0)

const initial = computed(() => email.value.charAt(0).toUpperCase())

const purchasePresentation = CREDIT_PURCHASE_PRESENTATION

watch(remainingUses, () => {
  countClass.value = 'flip-out'
  setTimeout(() => {
    countClass.value = 'flip-in'
    requestAnimationFrame(() =>
      requestAnimationFrame(() => (countClass.value = ''))
    )
  }, 160)
})

async function handlePurchaseSuccess() {
  showPurchaseFlow.value = false
  await entitlementStore.refresh(DEFAULT_PRODUCT_CODE)
}

onMounted(async () => {
  await userContext.initialise()
  await entitlementStore.initialise(DEFAULT_PRODUCT_CODE)
})
</script>

<style scoped lang="scss">
.credits-row {
  margin: 16px 0;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 16px 16px;
  border-bottom: 1px solid var(--color-border-dark);

  &:hover .credits-avatar {
    transform: scale(1.08);
  }
}

.credits-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #059669;
  color: #fff;
  font-size: 13px;
  font-weight: 500;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  user-select: none;
  transition: transform 200ms cubic-bezier(0.34, 1.56, 0.64, 1);
}

.credits-identity {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.credits-email {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-main-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}

.credits-meta {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  line-height: 1.3;
  margin-top: 1px;
}

.credits-count-wrap {
  display: inline-block;
  overflow: hidden;
  height: 1.3em;        // ← bump slightly to match line-height
  vertical-align: middle;
}

.credits-count {
  display: inline-block;
  font-weight: 700;
  color: var(--color-main-text);
  transition: transform 160ms cubic-bezier(0.4, 0, 0.2, 1), opacity 160ms ease;

  &.flip-out {
    transform: translateY(-100%);
    opacity: 0;
  }

  &.flip-in {
    transform: translateY(100%);
    opacity: 0;
  }
}

.buy-pill {
  display: inline-flex;
  align-items: center;
  padding: 5px 11px;
  background: rgba(5, 150, 105, 0.1);
  border: 1px solid rgba(5, 150, 105, 0.2);
  border-radius: 999px;
  color: #059669;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  flex-shrink: 0;
  outline: none;
  transition:
    background 160ms ease,
    border-color 160ms ease,
    transform 160ms cubic-bezier(0.34, 1.56, 0.64, 1),
    box-shadow 160ms ease;

  &:hover {
    background: rgba(5, 150, 105, 0.16);
    border-color: rgba(5, 150, 105, 0.35);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(5, 150, 105, 0.15);
  }

  &:active {
    transform: scale(0.96);
    box-shadow: none;
    background: rgba(5, 150, 105, 0.22);
  }

  &:focus-visible {
    box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.4);
  }

  &:disabled {
    opacity: 0.4;
    cursor: default;
    pointer-events: none;
  }
}
</style>
