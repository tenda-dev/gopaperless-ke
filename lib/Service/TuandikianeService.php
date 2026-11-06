<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\TuandikianeDraft;
use OCA\Libresign\Db\TuandikianeDraftMapper;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;

/**
 * Tuandikiane, the WhatsApp agreement intake, on the GoPaperless side
 * (docs/runbooks/tuandikiane.md).
 *
 * Tuandikiane holds no user token. It creates a draft here with a server key;
 * the service account holds it, in draft status, so no one is notified. The
 * sender opens the review link, signs in as usual and taps Send; a MIMI passkey
 * authorises that. The draft then moves to the sender, the sender's slot
 * becomes their account, and every other signer gets the WhatsApp card on the
 * number Tuandikiane gave. Those signers are bound the same way: the link that
 * reached their WhatsApp, a sign-in, a MIMI passkey.
 */
class TuandikianeService {
	/** The draft's folder: in the service account's LibreSign folder, then in the sender's. */
	public const FOLDER = 'tuandikiane-';
	/** Signer slots are email signers until someone takes them. Most have no email. */
	private const PLACEHOLDER_DOMAIN = '@placeholder.securesign.local';
	private const MAX_HOURS = 168;
	private const DEFAULT_HOURS = 24;

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IDBConnection $db,
		private IUserManager $users,
		private ITimeFactory $time,
		private TuandikianeDraftMapper $drafts,
		private RequestSignatureService $requestSignature,
		private FileElementService $fileElementService,
		private FileElementMapper $fileElements,
		private FileMapper $files,
		private SignRequestMapper $signRequests,
		private IdentifyMethodMapper $identifyMethods,
		private IdentifyMethodService $identifyMethodService,
		private FolderService $folders,
		private ValidateHelper $validateHelper,
		private WhatsAppService $whatsApp,
		private TuandikianeClient $client,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The X-Tuandikiane-Key header against the tuandikiane_api_key setting.
	 *
	 * @throws LibresignException 503 when no key is set, 401 when it does not match
	 */
	public function checkKey(string $given): void {
		$key = $this->appConfig->getValueString(Application::APP_ID, 'tuandikiane_api_key');
		if ($key === '') {
			throw new LibresignException('Tuandikiane is not set up on this instance.', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		if ($given === '' || !hash_equals($key, $given)) {
			throw new LibresignException('Wrong key.', Http::STATUS_UNAUTHORIZED);
		}
	}

	/**
	 * POST /tuandikiane/drafts. The same reference again answers with the draft
	 * it made, unless that one expired unsent: it is deleted and made again.
	 *
	 * @return array{draft_uuid: string, review_url: string, expires_at: string}
	 * @throws LibresignException 422 for a body that breaks the contract, 503 when the service account is missing
	 */
	public function createDraft(array $input): array {
		$data = $this->parse($input);
		$existing = $this->drafts->findByReference($data['reference']);
		if ($existing !== null && !$this->isExpiredDraft($existing)) {
			return $this->describe($existing);
		}
		if ($existing !== null) {
			$this->deleteDraft($existing);
		}
		$service = $this->serviceUser();

		$now = $this->time->getDateTime();
		$draft = new TuandikianeDraft();
		$draft->setDraftUuid(UUIDUtil::getUUID());
		$draft->setReference($data['reference']);
		$draft->setStatus(TuandikianeDraft::STATUS_DRAFT);
		$draft->setSlots([]);
		$draft->setCreatedAt($now);
		$draft->setExpiresAt((clone $now)->modify('+' . $data['hours'] . ' hours'));
		try {
			// The row first: it claims the reference, so a retry that races this one gets this draft.
			$draft = $this->drafts->insert($draft);
		} catch (DbException $e) {
			$raced = $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				? $this->drafts->findByReference($data['reference'])
				: null;
			if ($raced === null) {
				throw $e;
			}
			return $this->describe($raced);
		}
		try {
			$this->fill($draft, $data, $service);
		} catch (\Throwable $e) {
			$this->deleteDraft($draft);
			throw $e;
		}
		return $this->describe($draft);
	}

	/**
	 * The file, one email signer per slot, then the boxes on each slot's sign request.
	 */
	private function fill(TuandikianeDraft $draft, array $data, IUser $service): void {
		$this->validateHelper->validateBase64($data['base64']);
		$signers = [];
		foreach ($data['signers'] as $order => $signer) {
			$signers[] = [
				'identifyMethods' => [['method' => 'email', 'value' => $this->slotEmail($draft, $signer)]],
				// Unnamed, a slot reads "Signer 2" until someone takes it. Left empty,
				// LibreSign would show its placeholder email instead.
				'displayName' => $signer['name'] !== '' ? $signer['name'] : 'Signer ' . ($order + 1),
				'signingOrder' => $order + 1,
				'notify' => 0,
			];
		}
		$file = $this->asUser($service->getUID(), fn (): FileEntity => $this->requestSignature->save([
			'file' => ['base64' => $data['base64']],
			'name' => $data['name'],
			'userManager' => $service,
			'status' => FileStatus::DRAFT->value,
			'settings' => ['folderName' => self::FOLDER . $draft->getDraftUuid()],
			'signers' => $signers,
		]));
		$draft->setFileId($file->getId());
		// Recorded now, so that a failure below deletes the file with the draft.
		$draft = $this->drafts->update($draft);

		$slots = [];
		$slotIds = [];
		foreach ($data['signers'] as $signer) {
			$signRequest = $this->signRequests->getByIdentifierAndFileId('email', $this->slotEmail($draft, $signer), $file->getId());
			if ($signRequest === null) {
				throw new \RuntimeException('Signer slot ' . $signer['key'] . ' has no sign request');
			}
			$slotIds[$signer['key']] = $signRequest->getId();
			$slots[] = [
				'key' => $signer['key'],
				'phone' => $signer['phone'],
				'sender' => $signer['sender'],
				'named' => $signer['name'] !== '',
				'signRequestId' => $signRequest->getId(),
				'uid' => null,
				'card' => false,
			];
		}

		$pages = $file->getMetadata()['d'] ?? [];
		$elements = [];
		foreach ($data['boxes'] as $index => $box) {
			if (!isset($pages[$box['page'] - 1])) {
				throw new LibresignException("boxes[$index].page is past the document's last page", Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			$elements[] = [
				'fileId' => $file->getId(),
				'signRequestId' => $slotIds[$box['signer']],
				'type' => 'signature',
				'coordinates' => [
					'page' => $box['page'],
					'left' => $box['left'],
					'top' => $box['top'],
					'width' => $box['width'],
					'height' => $box['height'],
				],
			];
		}
		$this->validateHelper->validateVisibleElements($elements, ValidateHelper::TYPE_VISIBLE_ELEMENT_PDF);
		foreach ($elements as $element) {
			$this->fileElementService->saveVisibleElement($element);
		}
		$draft->setSlots($slots);
		$this->drafts->update($draft);
	}

	/**
	 * The body as the contract has it, or a 422 naming the first thing wrong.
	 * Messages are for Tuandikiane's developers, so they are not translated.
	 *
	 * @return array{reference: string, name: string, base64: string, hours: int,
	 *     signers: list<array{key: string, name: string, phone: string, email: ?string, sender: bool}>,
	 *     boxes: list<array{signer: string, page: int, left: int, top: int, width: int, height: int}>}
	 */
	private function parse(array $input): array {
		$fail = static fn (string $message) => new LibresignException($message, Http::STATUS_UNPROCESSABLE_ENTITY);

		$reference = is_string($input['reference'] ?? null) ? trim($input['reference']) : '';
		if ($reference === '' || strlen($reference) > 128) {
			throw $fail('reference is required, at most 128 characters');
		}
		// The name is the file's name too, so no path separators.
		$name = is_string($input['name'] ?? null) ? trim((string)preg_replace('#[\\\\/\x00-\x1f]+#', ' ', $input['name'])) : '';
		if ($name === '' || mb_strlen($name) > 200) {
			throw $fail('name is required, at most 200 characters');
		}
		$base64 = $input['file']['base64'] ?? null;
		if (!is_string($base64) || $base64 === '') {
			throw $fail('file.base64 is required');
		}

		$signers = [];
		$emails = [];
		foreach (array_values(is_array($input['signers'] ?? null) ? $input['signers'] : []) as $index => $signer) {
			$at = "signers[$index]";
			$key = is_array($signer) && is_string($signer['key'] ?? null) ? $signer['key'] : '';
			if (!preg_match('/^s[1-9]\d{0,2}$/D', $key) || isset($signers[$key])) {
				throw $fail("$at.key must be s1, s2, ... and unique");
			}
			// "" is allowed: senders rarely name themselves.
			$displayName = $signer['display_name'] ?? null;
			if (!is_string($displayName)) {
				throw $fail("$at.display_name must be a string, empty if unknown");
			}
			$displayName = trim($displayName);
			$phone = $signer['phone'] ?? null;
			if (!is_string($phone) || !preg_match('/^\+[1-9]\d{6,14}$/D', $phone)) {
				throw $fail("$at.phone must be E.164, like +254712345678");
			}
			$email = $signer['email'] ?? null;
			if ($email !== null) {
				$email = is_string($email) ? strtolower(trim($email)) : '';
				if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_ends_with($email, self::PLACEHOLDER_DOMAIN)) {
					throw $fail("$at.email must be an email address, or null when there is none");
				}
				if (isset($emails[$email])) {
					throw $fail("$at.email belongs to another signer too");
				}
				$emails[$email] = true;
			}
			$signers[$key] = [
				'key' => $key,
				// LibreSign refuses a display name over 64 characters.
				'name' => mb_substr($displayName, 0, 64),
				'phone' => $phone,
				'email' => $email,
				'sender' => ($signer['is_sender'] ?? false) === true,
			];
		}
		if ($signers === []) {
			throw $fail('signers is required');
		}
		// None when the sender only draws the agreement up between others.
		if (count(array_filter($signers, static fn (array $signer): bool => $signer['sender'])) > 1) {
			throw $fail('at most one signer may have is_sender true');
		}

		$boxes = [];
		foreach (array_values(is_array($input['boxes'] ?? null) ? $input['boxes'] : []) as $index => $box) {
			$at = "boxes[$index]";
			if (!is_array($box) || !isset($signers[$box['signer'] ?? ''])) {
				throw $fail("$at.signer must be one of the signers' keys");
			}
			foreach (['page' => 1, 'left' => 0, 'top' => 0, 'width' => 1, 'height' => 1] as $field => $minimum) {
				if (!is_int($box[$field] ?? null) || $box[$field] < $minimum) {
					throw $fail("$at.$field must be an integer, at least $minimum");
				}
			}
			$boxes[] = [
				'signer' => $box['signer'],
				'page' => $box['page'],
				'left' => $box['left'],
				'top' => $box['top'],
				'width' => $box['width'],
				'height' => $box['height'],
			];
		}

		$hours = $input['expires_in_hours'] ?? self::DEFAULT_HOURS;
		if (!is_int($hours) || $hours < 1) {
			throw $fail('expires_in_hours must be a whole number of hours, at least 1');
		}

		return [
			'reference' => $reference,
			'name' => $name,
			'base64' => $base64,
			'hours' => min($hours, self::MAX_HOURS),
			'signers' => array_values($signers),
			'boxes' => $boxes,
		];
	}

	/** The signer's own email, or one made from the draft and the slot: never their number. */
	private function slotEmail(TuandikianeDraft $draft, array $signer): string {
		return $signer['email'] ?? 'tuandikiane-' . $draft->getDraftUuid() . '-' . $signer['key'] . self::PLACEHOLDER_DOMAIN;
	}

	/** @return array{draft_uuid: string, review_url: string, expires_at: string} */
	public function describe(TuandikianeDraft $draft): array {
		return [
			'draft_uuid' => $draft->getDraftUuid(),
			'review_url' => $this->urls->linkToRouteAbsolute('libresign.tuandikiane.review', ['code' => $this->reviewCode($draft->getDraftUuid())]),
			'expires_at' => $draft->getExpiresAt()->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * The code in the review link: the draft's uuid and a MAC over it, like the
	 * WhatsApp card's code. Nothing is stored; whether it can still be used is
	 * the draft's own state (draftForCode).
	 */
	public function reviewCode(string $draftUuid): string {
		return $draftUuid . '.' . $this->mac($draftUuid);
	}

	private function mac(string $draftUuid): string {
		$mac = hash_hmac('sha256', 'tuandikiane-review:' . $draftUuid, $this->config->getSystemValueString('secret'), true);
		// 128 bits, URL-safe.
		return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
	}

	/** The draft a review code names while it can still be sent; null once it is sent or expired. */
	public function draftForCode(string $code): ?TuandikianeDraft {
		[$uuid, $mac] = explode('.', $code, 2) + [1 => ''];
		if ($uuid === '' || !hash_equals($this->mac($uuid), $mac)) {
			return null;
		}
		$draft = $this->drafts->findByDraftUuid($uuid);
		return $draft !== null && $this->isOpen($draft) ? $draft : null;
	}

	private function isOpen(TuandikianeDraft $draft): bool {
		return $draft->getStatus() === TuandikianeDraft::STATUS_DRAFT
			&& $draft->getExpiresAt() > $this->time->getDateTime();
	}

	private function isExpiredDraft(TuandikianeDraft $draft): bool {
		return $draft->getStatus() === TuandikianeDraft::STATUS_DRAFT && !$this->isOpen($draft);
	}

	/**
	 * What the review page shows: the document's pages in points, each box with
	 * its signer's name (top-left origin, as Tuandikiane sent them), and the
	 * signers in signing order.
	 *
	 * @return array{file: FileEntity, pages: list<array{w: float|int, h: float|int}>,
	 *     boxes: list<array{page: int, left: int, top: int, width: int, height: int, signer: string}>,
	 *     signers: list<array{name: string, sender: bool}>}
	 */
	public function review(TuandikianeDraft $draft): array {
		$file = $this->files->getById($draft->getFileId());
		$names = [];
		foreach ($this->signRequests->getByFileId($file->getId()) as $signRequest) {
			$names[$signRequest->getId()] = $signRequest->getDisplayName();
		}
		$pages = array_values(array_map(
			static fn (array $page): array => ['w' => $page['w'], 'h' => $page['h']],
			$file->getMetadata()['d'] ?? [],
		));
		$boxes = [];
		foreach ($this->fileElements->getByFileId($file->getId()) as $element) {
			$height = $pages[$element->getPage() - 1]['h'] ?? 0;
			$boxes[] = [
				'page' => $element->getPage(),
				'left' => (int)$element->getLlx(),
				'top' => (int)($height - $element->getUry()),
				'width' => $element->getUrx() - (int)$element->getLlx(),
				'height' => $element->getUry() - (int)$element->getLly(),
				'signer' => $names[$element->getSignRequestId()] ?? '',
			];
		}
		$signers = array_map(
			static fn (array $slot): array => ['name' => $names[$slot['signRequestId']] ?? '', 'sender' => $slot['sender']],
			$draft->getSlots() ?? [],
		);
		return ['file' => $file, 'pages' => $pages, 'boxes' => $boxes, 'signers' => $signers];
	}

	/** The draft's PDF, from its holder's files. */
	public function document(TuandikianeDraft $draft): File {
		$file = $this->files->getById($draft->getFileId());
		return $this->asUser($file->getUserId(), fn (): File => $this->folders->getFileByNodeId((int)$file->getNodeId()));
	}

	/**
	 * Send, once the controller has checked the MIMI passkey. The file moves to
	 * $sender the way a public workflow claim moves one (copied into their
	 * LibreSign folder, the File row repointed); then, in one transaction, the
	 * sender's slot becomes their account, the file goes to able-to-sign
	 * without notifications, and the draft is marked sent. The other signers'
	 * WhatsApp cards and Tuandikiane's "sent" follow.
	 *
	 * When the sender is not one of the signers, no slot is bound and every
	 * signer gets a card.
	 *
	 * @return ?string the sender's own signing card, for "Sign now"; null when they don't sign
	 * @throws LibresignException 410 when the draft can no longer be sent
	 */
	public function send(TuandikianeDraft $draft, IUser $sender): ?string {
		$file = $this->files->getById($draft->getFileId());
		$holder = $file->getUserId();
		$sourceId = (int)$file->getNodeId();
		// File I/O stays out of the transaction. The folder is named after the
		// draft, so a retry after a failure below finds this copy and reuses it.
		[$copy, $created] = $this->copyTo($file, $sender, $draft);

		$gone = false;
		$this->db->beginTransaction();
		try {
			$locked = $this->drafts->findByDraftUuidForUpdate($draft->getDraftUuid());
			if ($locked === null || !$this->isOpen($locked)) {
				$gone = true;
				throw new LibresignException('This link has expired.', Http::STATUS_GONE);
			}
			$file->setUserId($sender->getUID());
			$file->setNodeId($copy->getId());
			$this->files->update($file);

			$slots = $locked->getSlots() ?? [];
			foreach ($slots as $index => $slot) {
				if ($slot['sender']) {
					$slots[$index] = $this->bind($slots, $index, $sender);
				}
			}
			$this->publish($file, $sender);

			$locked->setSlots($slots);
			$locked->setStatus(TuandikianeDraft::STATUS_SENT);
			$locked->setSentAt($this->time->getDateTime());
			$locked = $this->drafts->update($locked);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// Someone else sent it, or it expired: this copy will never be used.
			// After any other failure it stays for the retry to reuse.
			if ($gone && $created) {
				$this->deleteQuietly($copy);
			}
			throw $e;
		}

		$this->removeHolderCopy($holder, $sourceId, $draft);
		$locked = $this->sendCards($locked, $file, $sender);
		$this->client->event($locked->getDraftUuid(), 'sent');

		foreach ($locked->getSlots() ?? [] as $slot) {
			if ($slot['sender']) {
				return $this->cardUrl($this->signRequests->getById($slot['signRequestId']));
			}
		}
		return null;
	}

	/**
	 * A slot the WhatsApp card code names that no account holds yet, on a sent
	 * draft. The card page sends its signer to take it first.
	 *
	 * @return array{draft: TuandikianeDraft, slot: array, signRequest: SignRequest}|null
	 */
	public function unboundSlot(string $cardCode): ?array {
		$signRequest = $this->whatsApp->signRequestForCode($cardCode);
		if ($signRequest === null) {
			return null;
		}
		$draft = $this->drafts->findByFileId((int)$signRequest->getFileId());
		if ($draft === null || $draft->getStatus() !== TuandikianeDraft::STATUS_SENT) {
			return null;
		}
		foreach ($draft->getSlots() ?? [] as $slot) {
			if ($slot['signRequestId'] === $signRequest->getId()) {
				return $slot['uid'] === null && $signRequest->getSigned() === null
					? ['draft' => $draft, 'slot' => $slot, 'signRequest' => $signRequest]
					: null;
			}
		}
		return null;
	}

	/**
	 * The signer who opened the card, signed in and passed the MIMI passkey
	 * takes the slot: its email identity becomes their account.
	 *
	 * @throws LibresignException 410 when the slot is gone or taken, 409 when they hold another slot here
	 */
	public function bindCard(string $cardCode, IUser $user): void {
		$found = $this->unboundSlot($cardCode);
		if ($found === null) {
			throw new LibresignException('This signing link has already been used.', Http::STATUS_GONE);
		}
		$this->db->beginTransaction();
		try {
			$locked = $this->drafts->findByDraftUuidForUpdate($found['draft']->getDraftUuid());
			$slots = $locked?->getSlots() ?? [];
			foreach ($slots as $index => $slot) {
				if ($slot['signRequestId'] !== $found['signRequest']->getId()) {
					continue;
				}
				if ($slot['uid'] !== null && $slot['uid'] !== $user->getUID()) {
					throw new LibresignException('This signing link has already been used.', Http::STATUS_GONE);
				}
				$slots[$index] = $this->bind($slots, $index, $user);
				$locked->setSlots($slots);
				$this->drafts->update($locked);
				$this->db->commit();
				return;
			}
			throw new LibresignException('This signing link has already been used.', Http::STATUS_GONE);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The slot as $user's: its sign request's email identity becomes their
	 * account in place, as RequesterReconciliationService::convertInPlace does,
	 * so the sign request and its boxes stay. The caller holds the transaction.
	 *
	 * @param list<array> $slots
	 * @return array the slot, bound
	 */
	private function bind(array $slots, int $index, IUser $user): array {
		$slot = $slots[$index];
		foreach ($slots as $other => $taken) {
			if ($other !== $index && $taken['uid'] === $user->getUID()) {
				throw new LibresignException('You already sign this agreement under another name.', Http::STATUS_CONFLICT);
			}
		}
		$bound = false;
		foreach ($this->identifyMethods->getIdentifyMethodsFromSignRequestId($slot['signRequestId']) as $method) {
			if ($method->getIdentifierKey() === 'email') {
				$method->setIdentifierKey('account');
				$method->setIdentifierValue($user->getUID());
				$this->identifyMethods->update($method);
				$bound = true;
			} elseif ($method->getIdentifierKey() === 'account') {
				if ($method->getIdentifierValue() !== $user->getUID()) {
					throw new LibresignException('This signing link has already been used.', Http::STATUS_GONE);
				}
				$bound = true;
			}
		}
		if (!$bound) {
			throw new \RuntimeException('Signer slot ' . $slot['key'] . ' has no identity to bind');
		}
		if (!($slot['named'] ?? true)) {
			// "Signer 2" becomes the name of the account that took the slot.
			$signRequest = $this->signRequests->getById($slot['signRequestId']);
			$signRequest->setDisplayName(mb_substr($user->getDisplayName(), 0, 64));
			$this->signRequests->update($signRequest);
		}
		// The next save() must see the account identity, not a cached email one,
		// or it deletes the sign request as a signer no longer on the list.
		$this->identifyMethodService->clearCache();
		$slot['uid'] = $user->getUID();
		return $slot;
	}

	/**
	 * Able to sign, through the same save() as Send in the app, with every
	 * signer as they stand and notify off: the WhatsApp cards are the request.
	 */
	private function publish(FileEntity $file, IUser $sender): void {
		$signers = [];
		foreach ($this->signRequests->getByFileId($file->getId()) as $signRequest) {
			$methods = [];
			foreach ($this->identifyMethods->getIdentifyMethodsFromSignRequestId($signRequest->getId()) as $method) {
				$methods[] = ['method' => $method->getIdentifierKey(), 'value' => $method->getIdentifierValue()];
			}
			$signers[] = [
				'identifyMethods' => $methods,
				'displayName' => $signRequest->getDisplayName(),
				'signingOrder' => $signRequest->getSigningOrder(),
				'notify' => 0,
			];
		}
		$this->requestSignature->save([
			'uuid' => $file->getUuid(),
			'userManager' => $sender,
			'status' => FileStatus::ABLE_TO_SIGN->value,
			'signers' => $signers,
		]);
	}

	/**
	 * The WhatsApp card to each other signer whose turn it is and who has not
	 * had one. On Send, and after each signature for the ordered flow. A card
	 * that fails to go is tried again at the next of those.
	 */
	public function sendCards(TuandikianeDraft $draft, FileEntity $file, IUser $sender): TuandikianeDraft {
		$slots = $draft->getSlots() ?? [];
		$changed = false;
		foreach ($slots as $index => $slot) {
			if ($slot['sender'] || $slot['card']) {
				continue;
			}
			try {
				$signRequest = $this->signRequests->getById($slot['signRequestId']);
			} catch (DoesNotExistException) {
				continue;
			}
			if ($signRequest->getSigned() !== null || $signRequest->getStatusEnum() !== SignRequestStatus::ABLE_TO_SIGN) {
				continue;
			}
			$result = $this->whatsApp->sendTo($file, $signRequest, $sender, $this->subFor($draft, $slot), $slot['phone']);
			// too_soon: one already went to this signer.
			if (in_array($result['status'], ['sent', 'too_soon'], true)) {
				$slots[$index]['card'] = true;
				$changed = true;
			} else {
				$this->logger->info('Tuandikiane signing card not sent', ['slot' => $slot['key'], 'error' => $result['error'] ?? null]);
			}
		}
		if (!$changed) {
			return $draft;
		}
		$draft->setSlots($slots);
		return $this->drafts->update($draft);
	}

	/**
	 * Who the payments service files the card under. A slot nobody holds yet has
	 * no sub, so it is named by the draft and its key; the receipt later finds
	 * the chat by the sign request either way.
	 */
	private function subFor(TuandikianeDraft $draft, array $slot): string {
		$user = $slot['uid'] !== null ? $this->users->get($slot['uid']) : null;
		$sub = $user instanceof IUser ? $this->whatsApp->subFor($user) : null;
		return $sub ?? 'tuandikiane:' . $draft->getDraftUuid() . ':' . $slot['key'];
	}

	public function cardUrl(SignRequest $signRequest): string {
		return $this->urls->linkToRouteAbsolute('libresign.whatsAppSign.page', ['code' => $this->whatsApp->linkCode($signRequest)]);
	}

	/**
	 * A signature on any document: Tuandikiane hears "signed" for a slot of its
	 * drafts, and "completed" once every slot has signed. Until then, the next
	 * signers' cards go out (ordered flow). Never throws.
	 */
	public function signed(FileEntity $file, SignRequest $signRequest): void {
		try {
			$draft = $this->drafts->findByFileId((int)$file->getId());
			if ($draft === null) {
				return;
			}
			$key = null;
			foreach ($draft->getSlots() ?? [] as $slot) {
				if ($slot['signRequestId'] === $signRequest->getId()) {
					$key = $slot['key'];
				}
			}
			if ($key === null) {
				return;
			}
			$this->client->event($draft->getDraftUuid(), 'signed', $key);
			$all = $this->signRequests->getByFileId((int)$file->getId());
			if ($all !== [] && array_filter($all, static fn (SignRequest $one): bool => $one->getSigned() === null) === []) {
				$this->client->event($draft->getDraftUuid(), 'completed');
				return;
			}
			$owner = $this->users->get($file->getUserId());
			if ($owner instanceof IUser) {
				$this->sendCards($draft, $file, $owner);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Tuandikiane signature follow-up failed', ['exception' => $e]);
		}
	}

	/**
	 * A draft nobody sent, with its file: the expiry job's, and a failed
	 * creation's. Never a sent one, which belongs to its sender now.
	 */
	public function deleteDraft(TuandikianeDraft $draft): void {
		if ($draft->getStatus() !== TuandikianeDraft::STATUS_DRAFT) {
			return;
		}
		$holder = '';
		$nodeId = 0;
		if ($draft->getFileId() > 0) {
			try {
				$file = $this->files->getById($draft->getFileId());
				$holder = $file->getUserId();
				$nodeId = (int)$file->getNodeId();
				$this->requestSignature->deleteRequestSignature(['file' => ['fileId' => $draft->getFileId()]]);
			} catch (DoesNotExistException) {
			}
		}
		$this->removeHolderCopy($holder ?: $this->appConfig->getValueString(Application::APP_ID, 'tuandikiane_service_uid'), $nodeId, $draft);
		$this->drafts->delete($draft);
	}

	/**
	 * @return array{0: File, 1: bool} the copy in the sender's folder, and whether this call made it
	 */
	private function copyTo(FileEntity $file, IUser $sender, TuandikianeDraft $draft): array {
		$source = $this->asUser($file->getUserId(), fn (): File => $this->folders->getFileByNodeId((int)$file->getNodeId()));
		return $this->asUser($sender->getUID(), function () use ($source, $draft): array {
			$base = $this->folders->getFolder();
			$name = self::FOLDER . $draft->getDraftUuid();
			$folder = $base->nodeExists($name) ? $base->get($name) : $base->newFolder($name);
			if (!$folder instanceof Folder) {
				throw new \RuntimeException('The draft folder is not a folder');
			}
			if ($folder->nodeExists($source->getName())) {
				$existing = $folder->get($source->getName());
				if (!$existing instanceof File) {
					throw new \RuntimeException('The draft folder holds something else under the document name');
				}
				return [$existing, false];
			}
			return [$folder->newFile($source->getName(), $source->getContent()), true];
		});
	}

	/** The draft's folder in its holder's files, found by the node or else by its name. */
	private function removeHolderCopy(string $holder, int $nodeId, TuandikianeDraft $draft): void {
		if ($holder === '') {
			return;
		}
		try {
			$this->asUser($holder, function () use ($nodeId, $draft): void {
				$name = self::FOLDER . $draft->getDraftUuid();
				$folder = null;
				if ($nodeId > 0) {
					try {
						$parent = $this->folders->getFileByNodeId($nodeId)->getParent();
						$folder = $parent->getName() === $name ? $parent : null;
					} catch (\Throwable) {
					}
				}
				if ($folder === null) {
					$base = $this->folders->getFolder();
					$folder = $base->nodeExists($name) ? $base->get($name) : null;
				}
				$folder?->delete();
			});
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove a Tuandikiane draft folder', ['exception' => $e]);
		}
	}

	private function deleteQuietly(File $node): void {
		try {
			$node->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove an unused Tuandikiane copy', ['exception' => $e]);
		}
	}

	/**
	 * FolderService works on one user at a time and other code in the request
	 * relies on it, so it goes back to whoever it had.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function asUser(string $uid, callable $work): mixed {
		$previous = $this->folders->getUserId();
		$this->folders->setUserId($uid);
		try {
			return $work();
		} finally {
			$this->folders->setUserId($previous);
		}
	}

	private function serviceUser(): IUser {
		$uid = $this->appConfig->getValueString(Application::APP_ID, 'tuandikiane_service_uid');
		$user = $uid === '' ? null : $this->users->get($uid);
		if (!$user instanceof IUser) {
			throw new LibresignException('Tuandikiane is not set up on this instance.', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		return $user;
	}
}
