/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import 'vue-sonner/style.css'
import './style/global.scss'
import './style/custom.scss'
// Imported last so it wins the cascade over server-side theme CSS.
import './style/force-light.scss'

import App from './App.vue'
import router from './router/router'
import { entitlementCoordinator } from './payment/entitlementCoordinator'

if (window.OCA && !window.OCA.LibreSign) {
	Object.assign(window.OCA, { LibreSign: {} })
}

const app = createApp(App)

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = OC
app.config.globalProperties.OCA = OCA

const pinia = createPinia()
app.use(pinia)
app.use(router)

// Start after Pinia is initialized and before mounting so consumers have a
// coordinator available from their first render. This only refreshes displayed
// entitlement state; settlement authority remains on the backend.
entitlementCoordinator.start(pinia)

if (import.meta.hot) {
	import.meta.hot.dispose(() => entitlementCoordinator.stop())
}

router.isReady().then(() => {
	app.mount('#content')
})
