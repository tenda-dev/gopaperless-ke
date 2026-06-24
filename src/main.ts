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
// Force RequestPicker styles into the main entry CSS so they are available
// even when the async CSS chunk for this shared component fails to preload.
import './components/Request/RequestPicker.vue'

import App from './App.vue'
import router from './router/router'

if (window.OCA && !window.OCA.LibreSign) {
	Object.assign(window.OCA, { LibreSign: {} })
}

const app = createApp(App)

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = OC
app.config.globalProperties.OCA = OCA

app.use(createPinia())
app.use(router)

router.isReady().then(() => {
	app.mount('#content')
})
