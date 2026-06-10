import { onMounted } from 'vue'

export function useForceLightMode(): void {
	onMounted(() => {
		const target =
			(document.getElementById('body-user') as HTMLElement | null)
			|| document.body

		if (!target) {
			return
		}

		target.removeAttribute('data-theme-dark')

		if (target.getAttribute('data-themes') === 'dark') {
			target.removeAttribute('data-themes')
		}

		target.classList.remove('theme-dark')
	})
}
