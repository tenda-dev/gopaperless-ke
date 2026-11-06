<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\TuandikianeClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The details form Tuandikiane links to when it found no agreement in the chat.
 * Public: the form token in the link is the only key, and Tuandikiane holds it.
 * The templates come from Tuandikiane; the answers go back to it, and on success
 * the browser goes straight to the review link of the draft it made.
 *
 * Server-rendered, no script: the kinds of agreement are links, the form a plain
 * POST carrying Nextcloud's request token, so the CSRF check stays on.
 */
class TuandikianeFormController extends Controller {
	/** What the inputs are for each field type in the contract. */
	private const INPUTS = [
		'text' => ['type' => 'text'],
		'textarea' => ['type' => 'textarea'],
		'phone' => ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel'],
		'email' => ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email'],
		'id_number' => ['type' => 'text', 'inputmode' => 'numeric', 'autocomplete' => 'off'],
		'money' => ['type' => 'text', 'inputmode' => 'numeric', 'autocomplete' => 'off'],
		'date' => ['type' => 'date'],
	];
	private const MAX_VALUE = 4000;

	public function __construct(
		IRequest $request,
		private TuandikianeClient $client,
		private IURLGenerator $urls,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The kinds of agreement, or one kind's fields: the one picked (?template=),
	 * else the one Tuandikiane prefilled, unless they asked to choose again.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/form/{token}')]
	public function page(string $token, string $template = '', string $choose = ''): TemplateResponse {
		$form = $this->load($token);
		if ($form instanceof TemplateResponse) {
			return $form;
		}
		$prefilled = $form['prefill']['template'] ?? null;
		$picked = $choose === '1' ? null : ($template !== '' ? $template : $prefilled);
		$values = $picked !== null && $picked === $prefilled ? $form['prefill']['values'] : [];
		return $this->render($token, $form, $picked, $values);
	}

	/**
	 * The answers, forwarded as they were typed. Tuandikiane checks them: 200
	 * goes on to the review link, 422 shows each field's error beside it.
	 */
	#[PublicPage]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/form/{token}')]
	public function submit(string $token, string $template = '', array $values = []): TemplateResponse|RedirectResponse {
		if (!self::isToken($token)) {
			return $this->expired();
		}
		$typed = [];
		foreach ($values as $name => $value) {
			if (is_string($name) && self::isName($name) && is_string($value)) {
				$typed[$name] = mb_substr(trim($value), 0, self::MAX_VALUE);
			}
		}
		try {
			$answer = $this->client->submitForm($token, $template, $typed);
		} catch (\Throwable $e) {
			$this->logger->warning('Tuandikiane did not take a details form', ['exception' => $e]);
			$answer = ['status' => 0, 'body' => []];
		}
		$reviewUrl = $answer['body']['review_url'] ?? null;
		if ($answer['status'] === Http::STATUS_OK && is_string($reviewUrl) && $this->isReviewUrl($reviewUrl)) {
			return new RedirectResponse($reviewUrl);
		}
		if (in_array($answer['status'], [Http::STATUS_NOT_FOUND, Http::STATUS_GONE], true)) {
			return $this->expired();
		}

		$form = $this->load($token);
		if ($form instanceof TemplateResponse) {
			return $form;
		}
		// 422: each error beside its field; one about the whole form comes as
		// "template", which is no field, so it shows above them.
		$errors = $answer['status'] === Http::STATUS_UNPROCESSABLE_ENTITY && is_array($answer['body']['errors'] ?? null)
			? array_filter($answer['body']['errors'], 'is_string')
			: [];
		if ($answer['status'] !== Http::STATUS_UNPROCESSABLE_ENTITY) {
			// 502 draft_failed (this instance refused the draft), Tuandikiane down,
			// or a review link that isn't ours. The typed values stay.
			$this->logger->warning('A details form was not taken', ['status' => $answer['status'], 'detail' => $answer['body']['detail'] ?? null]);
			$errors[''] = $this->l10n->t('Something went wrong on our side. Please try again in a few minutes.');
		}
		return $this->render($token, $form, $template, $typed, $errors);
	}

	/**
	 * Tuandikiane's form, its templates checked against the contract, or the page
	 * to show instead: expired for 404 and 410, unavailable for anything else.
	 *
	 * @return array{templates: list<array{id: string, title: string, description: string, fields: list<array{name: string, label: string, type: string, required: bool}>}>, prefill: array{template: ?string, values: array<string, string>}}|TemplateResponse
	 */
	private function load(string $token): array|TemplateResponse {
		if (!self::isToken($token)) {
			return $this->expired();
		}
		try {
			$answer = $this->client->form($token);
		} catch (\Throwable $e) {
			$this->logger->warning('Tuandikiane did not answer for a details form', ['exception' => $e]);
			return $this->notice('unavailable');
		}
		if (in_array($answer['status'], [Http::STATUS_NOT_FOUND, Http::STATUS_GONE], true)) {
			return $this->expired();
		}
		$templates = [];
		foreach (($answer['status'] === Http::STATUS_OK && is_array($answer['body']['templates'] ?? null)) ? $answer['body']['templates'] : [] as $template) {
			if (!is_array($template) || !is_string($template['id'] ?? null) || !self::isName($template['id'])) {
				continue;
			}
			$fields = [];
			foreach (is_array($template['fields'] ?? null) ? $template['fields'] : [] as $field) {
				if (is_array($field) && is_string($field['name'] ?? null) && self::isName($field['name'])) {
					$fields[] = [
						'name' => $field['name'],
						'label' => is_string($field['label'] ?? null) ? $field['label'] : $field['name'],
						'type' => is_string($field['type'] ?? null) && isset(self::INPUTS[$field['type']]) ? $field['type'] : 'text',
						'required' => ($field['required'] ?? false) === true,
					];
				}
			}
			$templates[] = [
				'id' => $template['id'],
				'title' => is_string($template['title'] ?? null) ? $template['title'] : $template['id'],
				'description' => is_string($template['description'] ?? null) ? $template['description'] : '',
				'fields' => $fields,
			];
		}
		if ($templates === []) {
			$this->logger->warning('Tuandikiane sent a details form with no usable template', ['status' => $answer['status']]);
			return $this->notice('unavailable');
		}
		$prefill = is_array($answer['body']['prefill'] ?? null) ? $answer['body']['prefill'] : [];
		return [
			'templates' => $templates,
			'prefill' => [
				'template' => is_string($prefill['template'] ?? null) ? $prefill['template'] : null,
				'values' => array_filter(is_array($prefill['values'] ?? null) ? $prefill['values'] : [], 'is_string'),
			],
		];
	}

	/**
	 * @param array<string, string> $values
	 * @param array<string, string> $errors by field name; '' for the form as a whole
	 */
	private function render(string $token, array $form, ?string $picked, array $values, array $errors = []): TemplateResponse {
		$chosen = null;
		foreach ($form['templates'] as $template) {
			if ($template['id'] === $picked) {
				$chosen = $template;
			}
		}
		// One kind only: nothing to pick.
		if ($chosen === null && count($form['templates']) === 1) {
			$chosen = $form['templates'][0];
		}
		$params = ['mode' => $chosen === null ? 'pick' : 'fill'];
		if ($chosen === null) {
			$params['choices'] = array_map(fn (array $template): array => [
				'title' => $template['title'],
				'description' => $template['description'],
				'url' => $this->urls->linkToRoute('libresign.tuandikianeForm.page', ['token' => $token, 'template' => $template['id']]),
			], $form['templates']);
		} else {
			$known = array_column($chosen['fields'], 'name');
			$params += [
				'template' => $chosen,
				'action' => $this->urls->linkToRoute('libresign.tuandikianeForm.submit', ['token' => $token]),
				'chooseUrl' => count($form['templates']) > 1
					? $this->urls->linkToRoute('libresign.tuandikianeForm.page', ['token' => $token, 'choose' => '1'])
					: '',
				'fields' => array_map(static fn (array $field): array => $field + [
					'input' => self::INPUTS[$field['type']],
					'value' => $values[$field['name']] ?? '',
					'error' => $errors[$field['name']] ?? '',
				], $chosen['fields']),
				// Errors about no field on this form, such as the template itself.
				'formErrors' => array_values(array_diff_key($errors, array_flip($known))),
			];
		}
		return $this->respond($params);
	}

	private function expired(): TemplateResponse {
		return $this->notice('expired');
	}

	private function notice(string $mode): TemplateResponse {
		return $this->respond(['mode' => $mode]);
	}

	private function respond(array $params): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		$response = new TemplateResponse(Application::APP_ID, 'tuandikiane_form', $params, TemplateResponse::RENDER_AS_GUEST);
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Only this instance's review page: the redirect follows whatever Tuandikiane
	 * answers, so it must not be able to send the browser anywhere else.
	 */
	private function isReviewUrl(string $url): bool {
		$parts = parse_url($url);
		$ours = parse_url($this->urls->getAbsoluteURL('/'));
		return is_array($parts) && is_array($ours)
			&& in_array($parts['scheme'] ?? '', ['https', 'http'], true)
			&& ($parts['scheme'] ?? '') === ($ours['scheme'] ?? '')
			&& strcasecmp($parts['host'] ?? '', $ours['host'] ?? '') === 0
			&& ($parts['port'] ?? null) === ($ours['port'] ?? null)
			&& !isset($parts['user']) && !isset($parts['query']) && !isset($parts['fragment'])
			&& preg_match('#^(?:/[\w.-]+)*?(?:/index\.php)?/apps/libresign/p/t/review/[\w.-]+$#D', $parts['path'] ?? '') === 1;
	}

	/** 32 random url-safe bytes are 43 characters; anything far off is not a token. */
	private static function isToken(string $token): bool {
		return preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $token) === 1;
	}

	private static function isName(string $name): bool {
		return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) === 1;
	}
}
