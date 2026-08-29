---
title: "PR #10 Technical Debt — Files Exceeding Size Targets"
type: runbook
status: active
domain: ops
layer: runbooks
owner: engineering
date: 2026-07-30
commit: "0dc4ee453"
related: []
---

# PR #10 Technical Debt — Files Exceeding Size Targets

During the PR #10 remediation, the following files still exceed the engineering-policy size targets (`ENGINEERING_POLICY.md` §4.1). They are registered as technical debt and should be split in follow-up PRs.

## Vue Components (>300 LOC)

| File | LOC | Action |
|------|-----|--------|
| `src/components/Payments/PaymentStep.vue` | 2,927 | Split into payment-method sub-components |
| `src/components/Payments/BackupPaymentStep.vue` | 2,604 | Split into payment-method sub-components |
| `src/components/RightSidebar/RequestSignatureTab.vue` | 1,510 | Extract workflow sections |
| `src/views/Validation.vue` | 1,288 | Extract validation sub-components |
| `src/components/Request/VisibleElements.vue` | 1,179 | Legacy editor; deprecate after VisibleElementsNext rollout |
| `src/views/SignPDF/_partials/Sign.vue` | 1,172 | Extract payment/signature sections |
| `src/composables/useWorkflowController.ts` | 1,148 | Split into workflow sub-composables |
| `src/views/CrlManagement/CrlManagement.vue` | 1,044 | Extract CRL table/filter components |
| `src/payment/drivers/mockPaymentDriver.ts` | 1,004 | Split driver methods |
| `src/components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts` | 985 | Split into Document/Pages/Interaction/Restore/Save composables |
| `src/payment/usePayment.ts` | 902 | Split payment flow composables |
| `src/views/Settings/SignatureStamp.vue` | 897 | Extract stamp preview/editor |
| `src/components/Request/RequestPicker.vue` | 782 | Extract picker sections |
| `src/components/RightSidebar/Workflow/WorkflowSigner.vue` | 779 | Extract signer card sections |
| `src/components/RightSidebar/EnvelopeFilesList.vue` | 775 | Extract envelope file rows |
| `src/views/CreateAccount.vue` | 762 | Extract account-creation sections |
| `src/views/Dashboard/components/MyDocumentsTable.vue` | 749 | Extract table sections |
| `src/components/Payments/CreditQuantitySelector.vue` | 605 | Extract slider/quantity controls |
| `src/components/Entitlement/SignCreditsBanner.vue` | 591 | Extract banner sections |
| `src/components/Request/VisibleElementsNext/PdfEditorNext.vue` | 522 | Extract PDF editor sections |
| `src/components/Request/IdentifySigner.vue` | 509 | Extract signer identification sections |
| `src/components/Certificate/CertificatePayment.vue` | 485 | Extract certificate payment sections |
| `src/components/RightSidebar/Workflow/WorkflowRequestSignatureTab.vue` | 426 | Extract workflow tab sections |
| `src/store/entitlement.ts` | 400 | Split entitlement store |
| `src/views/Dashboard/Dashboard.vue` | 393 | Extract dashboard sections |
| `src/components/Request/VisibleElementsNext/VeSignerSelect.vue` | 392 | Extract signer select sections |
| `src/views/FilesListNext/components/FilesNextRowMenu.vue` | 360 | Extract row menu sections |
| `src/components/Request/VisibleElementsNext/VeSignerSheet.vue` | 350 | Extract signer sheet sections |
| `src/components/Request/VisibleElementsNext/shared/visibleElementsShared.ts` | 337 | Split shared utilities |
| `src/components/Request/VisibleElementsNext/composables/useSignerColors.ts` | 312 | Split color composables |
| `src/composables/useCreditSlider.ts` | 312 | Split credit slider composables |
| `src/components/LeftSidebar/LeftSidebar.vue` | 307 | Extract sidebar sections |

## PHP Services (>400 LOC)

| File | LOC | Action |
|------|-----|--------|
| `lib/Db/SignRequestMapper.php` | 910 | Split into query-specific methods |
| `lib/Service/AccountService.php` | 857 | Extract account-creation and extended-account methods |
| `lib/Controller/AccountController.php` | 572 | Split into smaller controllers |
| `lib/Service/Payment/PaymentLifecycleService.php` | 493 | Extract payment lifecycle stages |
| `lib/Service/ExtendedAccount/ExtendedAccountService.php` | 381 | Extract certificate validity logic |

## Priority

1. **High:** Payment components (`PaymentStep.vue`, `BackupPaymentStep.vue`, `PaymentLifecycleService.php`) — revenue-critical.
2. **Medium:** Workflow components (`RequestSignatureTab.vue`, `useWorkflowController.ts`, `WorkflowRequestSignatureTab.vue`) — daily-use surface.
3. **Low:** Settings/admin components — less frequent use.

## Registration

These items should be copied into the canonical `ENGINEERING_POLICY.md` §14 Technical Debt Registry in the governing repository, each with a `TD-NNN` identifier, severity, owner, and target date.
