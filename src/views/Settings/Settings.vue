<!--
  - SPDX-FileCopyrightText: 2021 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div>
		<!-- <SupportProject /> -->
		<CertificateEngine />
		<SignatureEngine />
		<DownloadBinaries />
		<ConfigureCheck />
		<RootCertificateCfssl />
		<RootCertificateOpenSsl />
		<IdentificationFactors />
		<ExpirationRules />
		<Validation />
		<CrlValidation />
		<DocMDP />
		<SignatureFlow />
		<SigningMode v-if="false" />
		<AllowedGroups />
		<SignatureProfileSettings v-if="appearanceProfilesEnabled" />
		<LegalInformation />
		<IdentificationDocuments />
		<CollectMetadata />
		<SignatureStamp />
		<SignatureHashAlgorithm />
		<DefaultUserFolder />
		<Envelope />
		<Reminders />
		<TSA />
		<Confetti />
		<FreeCredits />
		<OneTimeSigning />
		<ProductPricing />
		<CertificateAccess />
		<FilesListColumns />
		<FilesListNext />
		<VisibleElementsNext />
		<SmsOtpConfig />
		<WebhookOtpConfig />
		<DarajaConfig />
		<DpoPaymentConfig />
		<PaymentVerificationDispatcherConfig />
	</div>
</template>

<script setup lang="ts">
import { defineAsyncComponent } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import AllowedGroups from './AllowedGroups.vue'
import CertificateAccess from './CertificateAccess.vue'
import CertificateEngine from './CertificateEngine.vue'
import ConfigureCheck from './ConfigureCheck.vue'
import CollectMetadata from './CollectMetadata.vue'
import Confetti from './Confetti.vue'
import CrlValidation from './CrlValidation.vue'
import DefaultUserFolder from './DefaultUserFolder.vue'
import DocMDP from './DocMDP.vue'
import DownloadBinaries from './DownloadBinaries.vue'
import Envelope from './Envelope.vue'
import FilesListColumns from './FilesListColumns.vue'
import FilesListNext from './FilesListNext.vue'
import VisibleElementsNext from './VisibleElementsNext.vue'
import ExpirationRules from './ExpirationRules.vue'
import FreeCredits from './FreeCredits.vue'
import OneTimeSigning from './OneTimeSigning.vue'
import ProductPricing from './ProductPricing.vue'
import IdentificationDocuments from './IdentificationDocuments.vue'
import IdentificationFactors from './IdentificationFactors.vue'
import LegalInformation from './LegalInformation.vue'
import Reminders from './Reminders.vue'
import RootCertificateCfssl from './RootCertificateCfssl.vue'
import RootCertificateOpenSsl from './RootCertificateOpenSsl.vue'
import SignatureEngine from './SignatureEngine.vue'
import SignatureFlow from './SignatureFlow.vue'
import SignatureHashAlgorithm from './SignatureHashAlgorithm.vue'
import SignatureStamp from './SignatureStamp.vue'
import SigningMode from './SigningMode.vue'
import SupportProject from './SupportProject.vue'
import TSA from './TSA.vue'
import Validation from './Validation.vue'
import SmsOtpConfig from './SmsOtpConfig.vue'
import DarajaConfig from "@/views/Settings/DarajaConfig.vue";
import DpoPaymentConfig from "@/views/Settings/DpoPaymentConfig.vue";
import PaymentVerificationDispatcherConfig from "@/views/Settings/PaymentVerificationDispatcherConfig.vue";
import WebhookOtpConfig from './WebhookOtpConfig.vue'

const appearanceProfilesEnabled = loadState('libresign', 'appearance_profiles_enabled', false)
const SignatureProfileSettings = defineAsyncComponent(() => import('./SignatureProfile/SignatureProfileSettings.vue'))

defineOptions({
	name: 'Settings',
})
</script>
