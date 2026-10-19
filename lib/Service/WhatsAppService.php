<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Vendor\Endroid\QrCode\QrCode;
use OCA\Libresign\Vendor\Endroid\QrCode\Writer\SvgWriter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Signing requests on WhatsApp, and the signing link with its QR.
 *
 * WhatsApp only carries the link: the signer stays an account signer, so
 * SecurySign signs with their own certificate. GoPaperless never holds the
 * number on an account. It names the signer by their OIDC sub, and the payments
 * service (the gopaperless repo, /external/whatsapp/*) looks the number up and
 * sends. The only number GoPaperless keeps is one the sender typed when adding
 * the signer, in the sign request's metadata.
 */
class WhatsAppService {
	/** Sign request metadata: present when the sender chose WhatsApp, ['to' => typed number or null]. */
	private const CHOICE = 'whatsapp';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IDBConnection $db,
		private IUserManager $userManager,
		private IdentifyMethodMapper $identifyMethods,
		private SignRequestMapper $signRequests,
		private SecurySignService $signa,
		private IURLGenerator $urls,
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The admin switch, and what it needs. While this is false everything this
	 * feature adds stays out of the way: the add-signer switch, the signer-row
	 * actions, the signing-link redirects, the request and receipt messages, and
	 * remembering the sub at login.
	 */
	public static function on(IAppConfig $appConfig): bool {
		return $appConfig->getValueBool(Application::APP_ID, 'whatsapp_enabled', false)
			&& $appConfig->getValueString(Application::APP_ID, 'whatsapp_api_url') !== ''
			&& $appConfig->getValueString(Application::APP_ID, 'whatsapp_api_key') !== '';
	}

	public function enabled(): bool {
		return self::on($this->appConfig);
	}

	/** Kenyan mobile numbers only, as the payments service takes them: 0712 345 678 → 254712345678. */
	public static function normalize(string $number): ?string {
		$digits = (string)preg_replace('/\D/', '', $number);
		if (str_starts_with($digits, '254')) {
			$digits = substr($digits, 3);
		}
		$digits = ltrim($digits, '0');
		return preg_match('/^[17]\d{8}$/', $digits) ? '254' . $digits : null;
	}

	/** 254712345678 → +254 7•• ••• 678, the same mask the payments service uses. */
	public static function mask(string $number): string {
		return '+' . substr($number, 0, 3) . ' ' . $number[3] . '•• ••• ' . substr($number, -3);
	}

	/** @return array{to: ?string}|null null unless the sender chose WhatsApp for this signer */
	public function choice(SignRequest $signRequest): ?array {
		$choice = ($signRequest->getMetadata() ?? [])[self::CHOICE] ?? null;
		return is_array($choice) ? ['to' => is_string($choice['to'] ?? null) ? $choice['to'] : null] : null;
	}

	/**
	 * "Send sign request on Whatsapp" in the add-signer form. The message goes
	 * when the signer is asked to sign (see requested()), to the typed number
	 * when there is one.
	 *
	 * @return array{send: bool, masked: ?string}
	 * @throws \InvalidArgumentException when the number is not a Kenyan mobile number
	 */
	public function choose(SignRequest $signRequest, bool $send, string $to): array {
		$number = trim($to) === '' ? null : self::normalize($to);
		if (trim($to) !== '' && $number === null) {
			throw new \InvalidArgumentException('Enter a Kenyan mobile number, like 0712 345 678.');
		}
		$metadata = $signRequest->getMetadata() ?? [];
		unset($metadata[self::CHOICE]);
		if ($send) {
			$metadata[self::CHOICE] = ['to' => $number];
		}
		$signRequest->setMetadata($metadata);
		$this->signRequests->update($signRequest);
		return ['send' => $send, 'masked' => $send && $number !== null ? self::mask($number) : null];
	}

	/**
	 * The add-signer form's check, before there is a sign request.
	 *
	 * @return array{available: bool, masked: ?string, reason: ?string}
	 */
	public function account(?IUser $signer): array {
		$reason = $this->enabled() ? $this->accountReason($signer) : 'disabled';
		$sub = $reason === null && $signer instanceof IUser ? $this->subFor($signer) : null;
		if ($sub === null) {
			return ['available' => false, 'masked' => null, 'reason' => $reason ?? 'no_sub'];
		}
		try {
			$data = $this->call('GET', 'recipient?' . http_build_query(['sub' => $sub]))['body'];
		} catch (\Throwable $e) {
			$this->logger->warning('The payments service did not answer a WhatsApp lookup', ['exception' => $e]);
			$data = [];
		}
		$available = (bool)($data['available'] ?? false);
		return ['available' => $available, 'masked' => $data['masked'] ?? null, 'reason' => $available ? null : ($data['reason'] ?? 'unavailable')];
	}

	/**
	 * The signer was just asked to sign: on request, when their turn comes, or
	 * on a reminder. Sends on WhatsApp too if the sender chose it when adding
	 * them. The payments service holds the limits: one message a day, retries
	 * 2 minutes apart, 3 tries a day.
	 *
	 * ponytail: runs inside the request, like the receipt. A background job if a
	 * document with many WhatsApp signers ever takes too long to request.
	 */
	public function requested(FileEntity $file, SignRequest $signRequest): void {
		if (!$this->enabled() || $this->choice($signRequest) === null) {
			return;
		}
		$sender = $this->userManager->get($file->getUserId());
		if (!$sender instanceof IUser) {
			return;
		}
		$result = $this->send($file, $signRequest, $sender);
		if ($result['status'] === 'failed') {
			$this->logger->info('WhatsApp signing request not sent', ['signRequestId' => $signRequest->getId(), 'error' => $result['error']]);
		}
	}

	public function signLink(SignRequest $signRequest): string {
		return $this->urls->linkToRouteAbsolute('libresign.page.sign', ['uuid' => $signRequest->getUuid()]);
	}

	/**
	 * The code in the card's "Sign with MIMI.ke" button, /p/whatsapp/<code>: the
	 * sign request's uuid and a MAC over it. The page it opens needs no login,
	 * so the uuid alone (it is in the signing email) must not be enough. Nothing
	 * is stored: the MAC is checked again on the way in.
	 */
	public function linkCode(SignRequest $signRequest): string {
		return $signRequest->getUuid() . '.' . $this->linkMac($signRequest->getUuid());
	}

	/** The sign request a link code names, or null for a code this instance did not make. */
	public function signRequestForCode(string $code): ?SignRequest {
		[$uuid, $mac] = explode('.', $code, 2) + [1 => ''];
		if ($uuid === '' || !hash_equals($this->linkMac($uuid), $mac)) {
			return null;
		}
		try {
			return $this->signRequests->getByUuid($uuid);
		} catch (\Throwable) {
			return null;
		}
	}

	private function linkMac(string $uuid): string {
		$mac = hash_hmac('sha256', 'whatsapp-sign:' . $uuid, $this->config->getSystemValueString('secret'), true);
		// 128 bits, URL-safe.
		return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
	}

	/** The link as an SVG data URI, drawn with the QR library the footer already uses. */
	public function qr(string $link): string {
		return (new SvgWriter())->write(new QrCode(data: $link, size: 240, margin: 8))->getDataUri();
	}

	/**
	 * What the signer row shows. Never the full number.
	 *
	 * lastError is the latest send's failure, including one WhatsApp reported
	 * after Twilio had accepted the message. chosen: the sender turned WhatsApp
	 * on when adding the signer, so it goes with the request. waitReason says
	 * why availableAgainAt is in the future: sent, retry_wait or daily_limit.
	 *
	 * @return array{available: bool, masked: ?string, reason: ?string, lastSentAt: ?string, availableAgainAt: ?string, waitReason: ?string, lastError: ?string, chosen: bool}
	 */
	public function recipient(FileEntity $file, SignRequest $signRequest): array {
		$choice = $this->choice($signRequest);
		$sub = $this->subToMessage($file, $signRequest, $reason, false);
		if ($sub === null) {
			return ['available' => false, 'masked' => null, 'reason' => $reason, 'lastSentAt' => null, 'availableAgainAt' => null, 'waitReason' => null, 'lastError' => null, 'chosen' => $choice !== null];
		}
		try {
			$data = $this->call('GET', 'recipient?' . http_build_query(['sub' => $sub, 'sign_request_uuid' => $signRequest->getUuid()]))['body'];
		} catch (\Throwable $e) {
			$this->logger->warning('The payments service did not answer a WhatsApp lookup', ['exception' => $e]);
			$data = [];
		}
		$typed = $choice['to'] ?? null;
		$available = $typed !== null || (bool)($data['available'] ?? false);
		return [
			'available' => $available,
			'masked' => $typed !== null ? self::mask($typed) : ($data['masked'] ?? null),
			'reason' => $available ? null : ($data['reason'] ?? 'unavailable'),
			'lastSentAt' => $data['last_sent_at'] ?? null,
			'availableAgainAt' => $data['available_again_at'] ?? null,
			'waitReason' => $data['wait_reason'] ?? null,
			'lastError' => $data['last_error'] ?? null,
			'chosen' => $choice !== null,
		];
	}

	/**
	 * A repeat right after a message went out (a double click, or "Request on
	 * WhatsApp" after the request already sent it) comes back "sent" from the
	 * payments service without a second message.
	 *
	 * @return array{status: string, error: ?string, availableAgainAt: ?string, waitReason?: ?string}
	 */
	public function send(FileEntity $file, SignRequest $signRequest, IUser $sender): array {
		$sub = $this->subToMessage($file, $signRequest, $reason, true);
		if ($sub === null) {
			return ['status' => 'failed', 'error' => $reason, 'availableAgainAt' => null];
		}
		$message = [
			'sub' => $sub,
			'sign_request_uuid' => $signRequest->getUuid(),
			'sender_name' => $sender->getDisplayName(),
			'document_name' => $file->getName(),
			// The card's "Sign with MIMI.ke" button opens /p/whatsapp/<link_code>.
			'link_code' => $this->linkCode($signRequest),
		];
		$typed = $this->choice($signRequest)['to'] ?? null;
		if ($typed !== null) {
			$message['to'] = $typed;
		}
		try {
			$answer = $this->call('POST', 'sign-request', $message);
		} catch (\Throwable $e) {
			// Shown as "Couldn't send on WhatsApp"; the transport error is for the log.
			$this->logger->warning('The payments service did not answer a WhatsApp send', ['exception' => $e]);
			return ['status' => 'failed', 'error' => 'unreachable', 'availableAgainAt' => null];
		}
		if ($answer['status'] === 429) {
			$detail = $answer['body']['detail'] ?? [];
			return ['status' => 'too_soon', 'error' => null, 'availableAgainAt' => $detail['available_again_at'] ?? null, 'waitReason' => $detail['reason'] ?? 'sent'];
		}
		return [
			'status' => (string)($answer['body']['status'] ?? 'failed'),
			'error' => $answer['body']['error'] ?? ($answer['status'] === 200 ? null : 'http_' . $answer['status']),
			'availableAgainAt' => null,
		];
	}

	/**
	 * "You signed …" in the chat. The payments service sends it only when the
	 * request went out on WhatsApp, so this asks for every account signer.
	 */
	public function receipt(FileEntity $file, SignRequest $signRequest): void {
		if (!$this->enabled() || $file->getNodeType() === 'envelope') {
			return;
		}
		$signer = $this->accountSigner($signRequest);
		$sub = $signer instanceof IUser ? $this->subFor($signer) : null;
		if ($sub === null) {
			return;
		}
		try {
			$this->call('POST', 'sign-receipt', [
				'sub' => $sub,
				'sign_request_uuid' => $signRequest->getUuid(),
				'document_name' => $file->getName(),
			]);
		} catch (\Throwable $e) {
			// The document is signed either way; a missing receipt is not worth an error.
			$this->logger->warning('WhatsApp signing receipt failed', ['exception' => $e]);
		}
	}

	/** The OIDC sub a user last signed in with: remembered at login, else read from user_oidc's sessions. */
	public function subFor(IUser $user): ?string {
		$sub = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'securysign_sub');
		if ($sub !== '') {
			return $sub;
		}
		// ponytail: user_oidc keeps a sub per live session and expires the rows, so someone with
		// no session since this shipped has to sign in once. A backfill command if that bites.
		try {
			$qb = $this->db->getQueryBuilder();
			$sub = $qb->select('sub')
				->from('user_oidc_sessions')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
				->andWhere($qb->expr()->eq('provider_id', $qb->createNamedParameter($this->signa->providerId(), IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'DESC')
				->setMaxResults(1)
				->executeQuery()
				->fetchOne();
		} catch (\Throwable $e) {
			$this->logger->info('Could not read user_oidc sessions for a sub', ['exception' => $e]);
			return null;
		}
		if (!is_string($sub) || $sub === '') {
			return null;
		}
		$this->config->setUserValue($user->getUID(), Application::APP_ID, 'securysign_sub', $sub);
		return $sub;
	}

	/**
	 * The sub to message, or null with the reason the row shows. Only account
	 * signers from the SecurySign login qualify: anyone else would sign with the
	 * local engine, which is not this flow.
	 */
	private function subToMessage(FileEntity $file, SignRequest $signRequest, ?string &$reason, bool $toSend): ?string {
		$signer = $this->accountSigner($signRequest);
		$reason = match (true) {
			!$this->enabled() => 'disabled',
			$file->getNodeType() === 'envelope' => 'envelope',
			$signRequest->getSigned() !== null => 'signed',
			$toSend && $signRequest->getStatusEnum() !== SignRequestStatus::ABLE_TO_SIGN => 'not_their_turn',
			default => $this->accountReason($signer),
		};
		if ($reason !== null) {
			return null;
		}
		$sub = $this->subFor($signer);
		$reason = $sub === null ? 'no_sub' : null;
		return $sub;
	}

	private function accountReason(?IUser $signer): ?string {
		return match (true) {
			!$signer instanceof IUser => 'not_account',
			$signer->getBackendClassName() !== 'user_oidc' || $this->signa->providerId() <= 0 => 'password_account',
			default => null,
		};
	}

	public function accountSigner(SignRequest $signRequest): ?IUser {
		foreach ($this->identifyMethods->getIdentifyMethodsFromSignRequestId($signRequest->getId()) as $method) {
			if ($method->getIdentifierKey() === 'account') {
				return $this->userManager->get($method->getIdentifierValue());
			}
		}
		return null;
	}

	/** @return array{status: int, body: array<string, mixed>} */
	private function call(string $method, string $path, ?array $json = null): array {
		$url = rtrim($this->appConfig->getValueString(Application::APP_ID, 'whatsapp_api_url'), '/') . '/external/whatsapp/' . $path;
		$options = [
			'headers' => [
				'X-API-Key' => $this->appConfig->getValueString(Application::APP_ID, 'whatsapp_api_key'),
				'Accept' => 'application/json',
			],
			'connect_timeout' => 3,
			'timeout' => 10,
			'http_errors' => false,
			// The admin sets this URL, and locally it is the host machine.
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($json !== null) {
			$options['json'] = $json;
		}
		$client = $this->http->newClient();
		$response = $method === 'GET' ? $client->get($url, $options) : $client->post($url, $options);
		$body = json_decode((string)$response->getBody(), true);
		return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
	}
}
