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
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\Payment\LinkPaymentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
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
 * (docs/runbooks/tuandikiane.md, and the contract in the payments repo's
 * docs/tuandikiane-contract.md).
 *
 * Tuandikiane holds no user token. It names everyone by MIMI account: the
 * moderator, who writes the agreement on WhatsApp, and each signer it found an
 * account for. GoPaperless makes the draft as the moderator's own, with those
 * signers as accounts and everyone else as a joiner's slot. The moderator opens
 * Seal & Deliver from WhatsApp, pays for the signers if their credits fall
 * short, and seals with their MIMI passkey, or by signing when they sign too.
 * Ready signers get their WhatsApp card at once; a joiner gets an invite to
 * MIMI and their slot becomes their account when Tuandikiane hears they joined.
 */
class TuandikianeService {
	/** The draft's folder in the moderator's LibreSign folder. */
	public const FOLDER = 'tuandikiane-';
	/** A joiner's slot is an email signer until their account takes it. Most have no email. */
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
		private SecurySignService $signa,
		private LinkPaymentService $payments,
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
	 * it made, unless that one expired unsealed: it is deleted and made again.
	 *
	 * @return array{draft_uuid: string, review_url: string, expires_at: string}
	 * @throws LibresignException 422 for a body that breaks the contract or a moderator GoPaperless can't use, 503 when MIMI doesn't answer
	 */
	public function createDraft(array $input): array {
		$data = $this->parse($input);
		$existing = $this->drafts->findByReference($data['reference']);
		if ($existing !== null && !$this->isExpiredDraft($existing)) {
			return $this->describe($existing);
		}

		// Accounts first, so a MIMI outage leaves nothing half made.
		$moderator = $this->account($data['moderator']);
		if ($moderator === null) {
			throw new LibresignException('moderator.sub has no MIMI account GoPaperless can use', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$accounts = [];
		$taken = [];
		foreach ($data['signers'] as $signer) {
			$user = $signer['sender'] ? $moderator : ($signer['sub'] !== null ? $this->account($signer['sub']) : null);
			// Two numbers that paid MIMI from one account: the second is a joiner's slot.
			$user = $user !== null && !isset($taken[$user->getUID()]) ? $user : null;
			$accounts[$signer['key']] = $user;
			if ($user !== null) {
				$taken[$user->getUID()] = true;
			}
		}

		if ($existing !== null) {
			$this->deleteDraft($existing);
		}
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
			$this->fill($draft, $data, $moderator, $accounts);
		} catch (\Throwable $e) {
			$this->deleteDraft($draft);
			throw $e;
		}
		return $this->describe($draft);
	}

	/** The GoPaperless account for a MIMI account, or null. MIMI not answering is a 503. */
	private function account(string $sub): ?IUser {
		try {
			return $this->signa->accountFor($sub);
		} catch (\RuntimeException $e) {
			$this->logger->warning('MIMI could not name a Tuandikiane account', ['exception' => $e]);
			throw new LibresignException('MIMI is not answering. Try again in a minute.', Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * The file as the moderator's, a signer per slot (their account, or a
	 * joiner's placeholder), then the boxes on each slot's sign request.
	 *
	 * @param array<string, ?IUser> $accounts by signer key
	 */
	private function fill(TuandikianeDraft $draft, array $data, IUser $moderator, array $accounts): void {
		$this->validateHelper->validateBase64($data['base64']);
		$signers = [];
		foreach ($data['signers'] as $signer) {
			$user = $accounts[$signer['key']];
			$signers[] = [
				'identifyMethods' => [$this->identity($draft, $signer, $user)],
				// What the moderator wrote, else the number. Never the account's
				// name: the moderator must not learn who has MIMI, or whose an
				// account is, before that person signs (signed() names them then).
				'displayName' => $signer['name'] !== '' ? $signer['name'] : $signer['phone'],
				// Everyone at once: a ready signer never waits for a joiner.
				'signingOrder' => 1,
				'notify' => 0,
			];
		}
		$file = $this->asUser($moderator->getUID(), fn (): FileEntity => $this->requestSignature->save([
			'file' => ['base64' => $data['base64']],
			'name' => $data['name'],
			'userManager' => $moderator,
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
			$user = $accounts[$signer['key']];
			$identity = $this->identity($draft, $signer, $user);
			$signRequest = $this->signRequests->getByIdentifierAndFileId($identity['method'], $identity['value'], $file->getId());
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
				'uid' => $user?->getUID(),
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

	/** @return array{method: string, value: string} */
	private function identity(TuandikianeDraft $draft, array $signer, ?IUser $user): array {
		return $user !== null
			? ['method' => 'account', 'value' => $user->getUID()]
			: ['method' => 'email', 'value' => $signer['email'] ?? 'tuandikiane-' . $draft->getDraftUuid() . '-' . $signer['key'] . self::PLACEHOLDER_DOMAIN];
	}

	/**
	 * The body as the contract has it, or a 422 naming the first thing wrong.
	 * Messages are for Tuandikiane's developers, so they are not translated.
	 *
	 * @return array{reference: string, name: string, base64: string, hours: int, moderator: string,
	 *     signers: list<array{key: string, name: string, phone: string, email: ?string, sender: bool, sub: ?string}>,
	 *     boxes: list<array{signer: string, page: int, left: int, top: int, width: int, height: int}>}
	 */
	private function parse(array $input): array {
		$fail = static fn (string $message) => new LibresignException($message, Http::STATUS_UNPROCESSABLE_ENTITY);
		$sub = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' && strlen($value) <= 255 ? trim($value) : null;

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
		$moderator = $sub($input['moderator']['sub'] ?? null);
		if ($moderator === null) {
			throw $fail('moderator.sub is required: the MIMI account that seals the agreement');
		}

		$signers = [];
		$emails = [];
		foreach (array_values(is_array($input['signers'] ?? null) ? $input['signers'] : []) as $index => $signer) {
			$at = "signers[$index]";
			$key = is_array($signer) && is_string($signer['key'] ?? null) ? $signer['key'] : '';
			if (!preg_match('/^s[1-9]\d{0,2}$/D', $key) || isset($signers[$key])) {
				throw $fail("$at.key must be s1, s2, ... and unique");
			}
			// "" is allowed: moderators rarely name themselves.
			$displayName = $signer['display_name'] ?? null;
			if (!is_string($displayName)) {
				throw $fail("$at.display_name must be a string, empty if unknown");
			}
			$displayName = trim($displayName);
			$phone = $signer['phone'] ?? null;
			if (!is_string($phone) || !preg_match('/^\+[1-9]\d{6,14}$/D', $phone)) {
				throw $fail("$at.phone must be E.164, like +254712345678");
			}
			// Their card or invite goes out on WhatsApp, which the payments service
			// only sends to Kenyan mobile numbers. The moderator may be anywhere.
			if (($signer['is_sender'] ?? false) !== true && WhatsAppService::normalize($phone) === null) {
				throw $fail("$at.phone must be a Kenyan mobile number, like +254712345678");
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
				'sub' => $sub($signer['sub'] ?? null),
			];
		}
		if ($signers === []) {
			throw $fail('signers is required');
		}
		// None when the moderator only draws the agreement up between others.
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
			'moderator' => $moderator,
			'signers' => array_values($signers),
			'boxes' => $boxes,
		];
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
	 * The code in the Seal & Deliver link: the draft's uuid and a MAC over it,
	 * like the WhatsApp card's code. Nothing is stored; what it can still do is
	 * the draft's own state.
	 */
	public function reviewCode(string $draftUuid): string {
		return $draftUuid . '.' . $this->mac('tuandikiane-review:' . $draftUuid);
	}

	private function mac(string $message): string {
		$mac = hash_hmac('sha256', $message, $this->config->getSystemValueString('secret'), true);
		// 128 bits, URL-safe.
		return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
	}

	/** The draft a Seal & Deliver code names, in any state; null for a bad code or an unknown draft. */
	public function draftForCode(string $code): ?TuandikianeDraft {
		[$uuid, $mac] = explode('.', $code, 2) + [1 => ''];
		if ($uuid === '' || !hash_equals($this->mac('tuandikiane-review:' . $uuid), $mac)) {
			return null;
		}
		return $this->drafts->findByDraftUuid($uuid);
	}

	/** Still waiting to be sealed, and not expired. */
	public function isOpen(TuandikianeDraft $draft): bool {
		return $draft->getStatus() === TuandikianeDraft::STATUS_DRAFT
			&& $draft->getExpiresAt() > $this->time->getDateTime();
	}

	private function isExpiredDraft(TuandikianeDraft $draft): bool {
		return $draft->getStatus() === TuandikianeDraft::STATUS_DRAFT && !$this->isOpen($draft);
	}

	public function moderator(TuandikianeDraft $draft): IUser {
		$user = $this->users->get($this->files->getById($draft->getFileId())->getUserId());
		if (!$user instanceof IUser) {
			throw new LibresignException('The moderator\'s account is gone.', Http::STATUS_GONE);
		}
		return $user;
	}

	/** The moderator's MIMI account, which their passkey answers for. */
	public function moderatorSub(TuandikianeDraft $draft): ?string {
		return $this->whatsApp->subFor($this->moderator($draft));
	}

	/** The moderator's own slot, when they sign too. */
	private function moderatorSlot(TuandikianeDraft $draft): ?array {
		foreach ($draft->getSlots() ?? [] as $slot) {
			if ($slot['sender']) {
				return $slot;
			}
		}
		return null;
	}

	/**
	 * What the page shows: the document's pages in points, each box with its
	 * signer's name (top-left origin, as Tuandikiane sent them), the signers in
	 * order, and the money: what one signature costs and the moderator's credits.
	 *
	 * @return array{file: FileEntity, pages: list<array{w: float|int, h: float|int}>,
	 *     boxes: list<array{page: int, left: int, top: int, width: int, height: int, signer: string}>,
	 *     signers: list<array{name: string, sender: bool}>, moderator: IUser,
	 *     money: ?array{price: int, credits: int}}
	 */
	public function review(TuandikianeDraft $draft): array {
		$file = $this->files->getById($draft->getFileId());
		$names = [];
		foreach ($this->signRequests->getByFileId($file->getId()) as $signRequest) {
			$names[$signRequest->getId()] = $signRequest->getDisplayName();
		}
		$pages = array_values(array_map(
			static fn (array $page): array => ['w' => (float)$page['w'], 'h' => (float)$page['h']],
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
			// Nothing about who has MIMI: a joiner's slot looks like any other.
			static fn (array $slot): array => ['name' => $names[$slot['signRequestId']] ?? '', 'sender' => $slot['sender']],
			$draft->getSlots() ?? [],
		);
		$moderator = $this->moderator($draft);
		$money = $this->payments->on() ? ['price' => $this->payments->price(), 'credits' => $this->payments->credits($moderator)] : null;
		return ['file' => $file, 'pages' => $pages, 'boxes' => $boxes, 'signers' => $signers, 'moderator' => $moderator, 'money' => $money];
	}

	/** The draft's PDF, from the moderator's files. */
	public function document(TuandikianeDraft $draft): File {
		$file = $this->files->getById($draft->getFileId());
		return $this->asUser($file->getUserId(), fn (): File => $this->folders->getFileByNodeId((int)$file->getNodeId()));
	}

	/**
	 * Signatures the moderator's credits must cover to seal: every signer's with
	 * "Pay for signers" on, none with it off (each pays on their card, the
	 * moderator too). Zero with credits off.
	 */
	public function creditsNeeded(TuandikianeDraft $draft, bool $payForSigners): int {
		return $this->payments->on() && $payForSigners ? count($draft->getSlots() ?? []) : 0;
	}

	/** How many signatures the moderator must buy before sealing. */
	public function shortfall(TuandikianeDraft $draft, bool $payForSigners): int {
		$needed = $this->creditsNeeded($draft, $payForSigners);
		return $needed === 0 ? 0 : max(0, $needed - $this->payments->credits($this->moderator($draft)));
	}

	/**
	 * Seal, once the controller has the moderator's MIMI passkey (or, when they
	 * sign too, before their signature, which is then the seal). In one
	 * transaction the file goes to able-to-sign with the moderator's credits
	 * reserved for each signer when they pay, and the draft is marked sealed.
	 * A moderator who doesn't sign delivers now; one who does, at their
	 * signature (signed()).
	 *
	 * @return ?string the moderator's own signing card, when they sign
	 * @throws LibresignException 410 when it can no longer be sealed, 402 when the credits fall short
	 */
	public function seal(TuandikianeDraft $draft, bool $payForSigners): ?string {
		$file = $this->files->getById($draft->getFileId());
		$moderator = $this->moderator($draft);
		if ($this->shortfall($draft, $payForSigners) > 0) {
			throw new LibresignException('Buy the signatures first.', Http::STATUS_PAYMENT_REQUIRED);
		}
		$signs = $this->moderatorSlot($draft) !== null;

		$this->db->beginTransaction();
		try {
			$locked = $this->drafts->findByDraftUuidForUpdate($draft->getDraftUuid());
			if ($locked === null || !$this->isOpen($locked)) {
				throw new LibresignException('This link has expired.', Http::STATUS_GONE);
			}
			$this->publish($file, $moderator, $payForSigners);
			$locked->setStatus($signs ? TuandikianeDraft::STATUS_SEALED : TuandikianeDraft::STATUS_SENT);
			$locked->setSentAt($this->time->getDateTime());
			$locked = $this->drafts->update($locked);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		if (!$signs) {
			$this->deliver($locked, $file, $moderator);
			return null;
		}
		return $this->moderatorCard($locked);
	}

	/** The WhatsApp number a Tuandikiane signer writes from, for their M-Pesa prompt; '' for any other signer. */
	public function phoneFor(SignRequest $signRequest): string {
		return $this->slotFor($signRequest)['phone'] ?? '';
	}

	/**
	 * The name a signer signs under. A Tuandikiane slot the moderator left
	 * unnamed shows the number until its signer signs, so they sign under
	 * their own account's name; anyone else under their sign request's.
	 */
	public function signerName(SignRequest $signRequest, IUser $signer): string {
		$slot = $this->slotFor($signRequest);
		return $slot !== null && !($slot['named'] ?? true)
			? mb_substr($signer->getDisplayName(), 0, 64)
			: $signRequest->getDisplayName();
	}

	private function slotFor(SignRequest $signRequest): ?array {
		foreach ($this->drafts->findByFileId((int)$signRequest->getFileId())?->getSlots() ?? [] as $slot) {
			if ($slot['signRequestId'] === $signRequest->getId()) {
				return $slot;
			}
		}
		return null;
	}

	/** The card a slot signs on. */
	public function slotCard(array $slot): string {
		return $this->cardUrl($this->signRequests->getById($slot['signRequestId']));
	}

	/** The moderator's own card, when they sign. */
	public function moderatorCard(TuandikianeDraft $draft): ?string {
		$slot = $this->moderatorSlot($draft);
		return $slot === null ? null : $this->slotCard($slot);
	}

	/**
	 * Able to sign, through the same save() as Send in the app, every signer as
	 * they stand, notify off: the WhatsApp cards are the request. With "Pay for
	 * signers" each is the moderator's sponsored signer, so their credits are
	 * reserved now.
	 */
	private function publish(FileEntity $file, IUser $moderator, bool $payForSigners): void {
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
			] + ($payForSigners ? ['sponsorship' => ['type' => SponsorshipType::REQUESTER->value]] : []);
		}
		$this->requestSignature->save([
			'uuid' => $file->getUuid(),
			'userManager' => $moderator,
			'status' => FileStatus::ABLE_TO_SIGN->value,
			'signers' => $signers,
		]);
	}

	/**
	 * Everyone hears: each ready signer's card, and Tuandikiane's "sent" with the
	 * joiners' invites, which it sends from its own WhatsApp templates.
	 */
	private function deliver(TuandikianeDraft $draft, FileEntity $file, IUser $moderator): void {
		$draft = $this->sendCards($draft, $file, $moderator);
		$invites = [];
		foreach ($draft->getSlots() ?? [] as $slot) {
			if ($slot['uid'] === null) {
				$invites[] = ['signer' => $slot['key'], 'phone' => $slot['phone'], 'url' => $this->joinUrl($draft, $slot['key'])];
			}
		}
		$this->client->event($draft->getDraftUuid(), 'sent', null, ['invites' => $invites]);
	}

	/**
	 * The WhatsApp card to each ready signer who can sign and has not had one.
	 * At delivery, and when a joiner's account takes their slot. A card that
	 * fails to go is tried again at the next of those.
	 */
	public function sendCards(TuandikianeDraft $draft, FileEntity $file, IUser $moderator): TuandikianeDraft {
		$slots = $draft->getSlots() ?? [];
		$changed = false;
		foreach ($slots as $index => $slot) {
			if ($slot['card'] || $slot['uid'] === null) {
				continue;
			}
			$user = $this->users->get($slot['uid']);
			$sub = $user instanceof IUser ? $this->whatsApp->subFor($user) : null;
			try {
				$signRequest = $this->signRequests->getById($slot['signRequestId']);
			} catch (DoesNotExistException) {
				continue;
			}
			if ($sub === null || $signRequest->getSigned() !== null || $signRequest->getStatusEnum() !== SignRequestStatus::ABLE_TO_SIGN) {
				continue;
			}
			$result = $this->whatsApp->sendTo($file, $signRequest, $moderator, $sub, $slot['phone']);
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

	public function cardUrl(SignRequest $signRequest): string {
		return $this->urls->linkToRouteAbsolute('libresign.whatsAppSign.page', ['code' => $this->whatsApp->linkCode($signRequest)]);
	}

	/** The joiner's invite link: MIMI first, then their card. */
	public function joinUrl(TuandikianeDraft $draft, string $key): string {
		$payload = $draft->getDraftUuid() . '.' . $key;
		return $this->urls->linkToRouteAbsolute('libresign.tuandikiane.join', ['code' => $payload . '.' . $this->mac('tuandikiane-join:' . $payload)]);
	}

	/**
	 * The draft and slot an invite code names, on a sealed draft.
	 *
	 * @return array{draft: TuandikianeDraft, slot: array}|null
	 */
	public function slotForJoinCode(string $code): ?array {
		$parts = explode('.', $code);
		if (count($parts) !== 3 || !hash_equals($this->mac('tuandikiane-join:' . $parts[0] . '.' . $parts[1]), $parts[2])) {
			return null;
		}
		$draft = $this->drafts->findByDraftUuid($parts[0]);
		if ($draft === null || $this->isOpen($draft) || $draft->getStatus() === TuandikianeDraft::STATUS_DRAFT) {
			return null;
		}
		foreach ($draft->getSlots() ?? [] as $slot) {
			if ($slot['key'] === $parts[1]) {
				return ['draft' => $draft, 'slot' => $slot];
			}
		}
		return null;
	}

	/**
	 * Tuandikiane heard that $phone now pays MIMI as $sub: every joiner's slot
	 * on this draft with that number becomes that account, and, once the draft
	 * is delivered, their card goes. A repeat changes nothing.
	 *
	 * @return list<string> the keys that account now holds
	 * @throws LibresignException 404 unknown draft, 422 a number or account that can't sign, 503 MIMI not answering
	 */
	public function join(string $draftUuid, string $phone, string $sub): array {
		$draft = $this->drafts->findByDraftUuid($draftUuid);
		if ($draft === null) {
			throw new LibresignException('Unknown draft.', Http::STATUS_NOT_FOUND);
		}
		$number = WhatsAppService::normalize($phone);
		$user = $number === null ? null : $this->account($sub);
		if ($user === null) {
			throw new LibresignException('phone must be a Kenyan mobile number and sub a MIMI account GoPaperless can use', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$held = [];
		$bound = false;
		$this->db->beginTransaction();
		try {
			$locked = $this->drafts->findByDraftUuidForUpdate($draftUuid);
			$slots = $locked?->getSlots() ?? [];
			foreach ($slots as $index => $slot) {
				if (WhatsAppService::normalize($slot['phone']) !== $number) {
					continue;
				}
				if ($slot['uid'] === null) {
					try {
						$slots[$index] = $this->bind($slots, $index, $user);
						$bound = true;
					} catch (LibresignException $e) {
						// They sign this agreement under another slot already.
						$this->logger->info('A Tuandikiane joiner was not bound', ['slot' => $slot['key'], 'reason' => $e->getMessage()]);
						continue;
					}
				}
				if ($slots[$index]['uid'] === $user->getUID()) {
					$held[] = $slot['key'];
				}
			}
			$locked?->setSlots($slots);
			$locked = $locked === null ? null : $this->drafts->update($locked);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		if ($locked !== null && $bound && $locked->getStatus() !== TuandikianeDraft::STATUS_DRAFT) {
			$this->sendCards($locked, $this->files->getById($locked->getFileId()), $this->moderator($locked));
		}
		return $held;
	}

	/**
	 * Someone opened a joiner's invite and answered with the passkey of MIMI
	 * account $sub: the slot becomes that account, whatever number it paid MIMI
	 * with, or none. The invite went only to the slot's WhatsApp and the
	 * passkey proves the account, so nothing here trusts a typed number.
	 *
	 * @return string their card
	 * @throws LibresignException 410 no such invite, 409 the slot is another account's or this one signs under another slot, 422 an account that can't sign, 503 MIMI not answering
	 */
	public function claim(string $code, string $sub): string {
		$found = $this->slotForJoinCode($code);
		if ($found === null) {
			throw new LibresignException('This link has expired.', Http::STATUS_GONE);
		}
		$user = $this->account($sub);
		if ($user === null) {
			throw new LibresignException('That MIMI account can\'t sign yet. Finish setting it up on MIMI, then open your link again.', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$slot = null;
		$this->db->beginTransaction();
		try {
			$locked = $this->drafts->findByDraftUuidForUpdate($found['draft']->getDraftUuid());
			$slots = $locked?->getSlots() ?? [];
			foreach ($slots as $index => $one) {
				if ($one['key'] !== $found['slot']['key']) {
					continue;
				}
				if ($one['uid'] === null) {
					$slots[$index] = $this->bind($slots, $index, $user);
				}
				$slot = $slots[$index];
			}
			if ($locked === null || $slot === null || $slot['uid'] !== $user->getUID()) {
				throw new LibresignException('Someone else already signs from this link.', Http::STATUS_CONFLICT);
			}
			$locked->setSlots($slots);
			$locked = $this->drafts->update($locked);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		try {
			// Their way back in later; they go to the card now either way.
			$this->sendCards($locked, $this->files->getById($locked->getFileId()), $this->moderator($locked));
		} catch (\Throwable $e) {
			$this->logger->warning('A Tuandikiane joiner\'s card was not sent', ['exception' => $e]);
		}
		return $this->slotCard($slot);
	}

	/**
	 * The slot as $user's: its sign request's email identity becomes their
	 * account in place, as RequesterReconciliationService::convertInPlace does,
	 * so the sign request, its boxes and any sponsorship stay. The caller holds
	 * the transaction.
	 *
	 * @param list<array> $slots
	 * @return array the slot, bound
	 */
	private function bind(array $slots, int $index, IUser $user): array {
		$slot = $slots[$index];
		foreach ($slots as $other => $taken) {
			if ($other !== $index && $taken['uid'] === $user->getUID()) {
				throw new LibresignException('Already signs this agreement under another name.', Http::STATUS_CONFLICT);
			}
		}
		$bound = false;
		foreach ($this->identifyMethods->getIdentifyMethodsFromSignRequestId($slot['signRequestId']) as $method) {
			if ($method->getIdentifierKey() === 'email') {
				$method->setIdentifierKey('account');
				$method->setIdentifierValue($user->getUID());
				$this->identifyMethods->update($method);
				$bound = true;
			}
		}
		if (!$bound) {
			throw new \RuntimeException('Signer slot ' . $slot['key'] . ' has no identity to bind');
		}
		// The next save() must see the account identity, not a cached email one,
		// or it deletes the sign request as a signer no longer on the list.
		$this->identifyMethodService->clearCache();
		$slot['uid'] = $user->getUID();
		return $slot;
	}

	/**
	 * A signature on any document: Tuandikiane hears "signed" for a slot of its
	 * drafts, and "completed" with the signed PDF once every slot has signed.
	 * The moderator's own signature on a sealed draft delivers it. Never throws.
	 */
	public function signed(FileEntity $file, SignRequest $signRequest): void {
		try {
			$draft = $this->drafts->findByFileId((int)$file->getId());
			if ($draft === null) {
				return;
			}
			$key = null;
			$moderatorSigned = false;
			$signer = null;
			foreach ($draft->getSlots() ?? [] as $slot) {
				if ($slot['signRequestId'] === $signRequest->getId()) {
					$key = $slot['key'];
					$moderatorSigned = $slot['sender'];
					$signer = !($slot['named'] ?? true) && $slot['uid'] !== null ? $this->users->get($slot['uid']) : null;
				}
			}
			if ($key === null) {
				return;
			}
			if ($signer instanceof IUser) {
				// Signed: the number they showed as becomes their name.
				$signRequest->setDisplayName(mb_substr($signer->getDisplayName(), 0, 64));
				$this->signRequests->update($signRequest);
			}
			if ($moderatorSigned && $draft->getStatus() === TuandikianeDraft::STATUS_SEALED) {
				$draft->setStatus(TuandikianeDraft::STATUS_SENT);
				$draft = $this->drafts->update($draft);
				$this->deliver($draft, $file, $this->moderator($draft));
			}
			$this->client->event($draft->getDraftUuid(), 'signed', $key);
			$all = $this->signRequests->getByFileId((int)$file->getId());
			if ($all !== [] && array_filter($all, static fn (SignRequest $one): bool => $one->getSigned() === null) === []) {
				$this->client->event($draft->getDraftUuid(), 'completed', null, $this->signedDocument($file));
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Tuandikiane signature follow-up failed', ['exception' => $e]);
		}
	}

	/** The signed PDF for Tuandikiane to send everyone, or nothing when it can't be read. */
	private function signedDocument(FileEntity $file): array {
		try {
			$pdf = $this->asUser($file->getUserId(), fn (): File => $this->folders->getFileByNodeId((int)($file->getSignedNodeId() ?: $file->getNodeId())));
			return ['document' => ['name' => $file->getName(), 'pdf_base64' => base64_encode($pdf->getContent())]];
		} catch (\Throwable $e) {
			$this->logger->warning('No signed PDF for Tuandikiane', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * A draft nobody sealed, with its file: the expiry job's, and a failed
	 * creation's. Never a sealed one, which is the moderator's document now.
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
		$this->removeFolder($holder, $nodeId, $draft);
		$this->drafts->delete($draft);
	}

	/** The draft's folder in the moderator's files, found by the node or else by its name. */
	private function removeFolder(string $holder, int $nodeId, TuandikianeDraft $draft): void {
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
}
