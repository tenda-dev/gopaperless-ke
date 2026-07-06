(function () {
	"use strict"

	const APP_NAME = "GoPaperless"
	const MODAL_STORAGE_KEY = "gopaperless-onboarding-seen"

	/*
	|--------------------------------------------------------------------------
	| Social Links Config. /apps-extra/libresign/img/{file-name}.svg
	|--------------------------------------------------------------------------
	*/

	const SOCIAL_LINKS = [
		{
			name: "Facebook",
			url: "https://web.facebook.com/tendaworld/",
			icon: OC.filePath('libresign', '', 'img/facebook.png'),
		},
		{
			name: "TikTok",
			url: "https://www.tiktok.com/@tendaworld",
			icon: OC.filePath('libresign', '', 'img/tiktok.png'),
		},
		{
			name: "X",
			url: "https://x.com/tenda_world",
			icon: OC.filePath('libresign', '', 'img/x.png'),
		},
		{
			name: "LinkedIn",
			url: "https://www.linkedin.com/company/tenda-world/",
			icon: OC.filePath('libresign', '', 'img/linkedin.png'),
		},
	]

	/*
	|--------------------------------------------------------------------------
	| Title Update
	|--------------------------------------------------------------------------
	*/

	function updatePageTitle() {
		let title = document.title

		if (title.startsWith("LibreSign - ")) {
			title = title.replace(
				"LibreSign - ",
				`${APP_NAME} - Upload and sign documents`
			)

			document.title = title
		}
	}
})()
