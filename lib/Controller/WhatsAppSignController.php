<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\UserElementMapper;
use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Exception\SecurySignApprovalRequired;
use OCA\Libresign\Exception\SecurySignUnavailable;
use OCA\Libresign\Service\FolderService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Payment\LinkPaymentService;
use OCA\Libresign\Service\RequestMetadataService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\SignerElementsService;
use OCA\Libresign\Service\SignFileService;
use OCA\Libresign\Service\Sponsorship\SigningCoverageService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCA\Libresign\Service\TuandikianeService;
use OCA\Libresign\Service\WhatsAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Signing from the WhatsApp card's "Sign with MIMI.ke" button, with no
 * GoPaperless login. The link code names the sign request
 * (WhatsAppService::linkCode). The proof is the passkey on SecurySign's frame:
 * the token is bound to this document's hash and to the signer's account
 * (LOA-4, by their certificate's email), so no one else's passkey can approve.
 * The certificate is the one remembered at the signer's last sign-in.
 */
class WhatsAppSignController extends Controller {
	public function __construct(
		IRequest $request,
		private WhatsAppService $whatsApp,
		private SecurySignService $signa,
		private SignFileService $signFile,
		private FileMapper $files,
		private FileElementMapper $fileElements,
		private UserElementMapper $userElements,
		private SignerElementsService $signerElements,
		private FolderService $folders,
		private IdentifyMethodService $identifyMethods,
		private RequestMetadataService $metadata,
		private SigningCoverageService $coverage,
		private SigningSettlementService $settlement,
		private TuandikianeService $tuandikiane,
		private LinkPaymentService $payments,
		private IAppConfig $appConfig,
		private IUserManager $users,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/whatsapp/{code}')]
	public function page(string $code): TemplateResponse|RedirectResponse {
		$found = $this->find($code);
		$state = $found['state'];
		// These three go on to a passkey or a sign-in, so the browser is asked first:
		// here if its user agent gives it away, otherwise by whatsapp-sign.ts. The others
		// need neither here, and "Sign another document" on 'signed' is caught at the
		// login page.
		$checked = in_array($state, ['ready', 'login', 'no_credits'], true);
		$userAgent = $this->request->getHeader('User-Agent');
		if ($checked && self::isInAppBrowser($userAgent)) {
			$state = 'browser';
		}
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		if ($checked) {
			Util::addScript(Application::APP_ID, 'libresign-whatsapp-sign');
		}
		$response = new TemplateResponse(Application::APP_ID, 'whatsapp_sign', [
			'state' => $state,
			'documentName' => $found['file']?->getName() ?? '',
			'senderName' => $found['sender']?->getDisplayName() ?? '',
			'signUrl' => $this->urls->linkToRoute('libresign.whatsAppSign.sign', ['code' => $code]),
			'signLink' => $found['signRequest'] !== null ? $this->whatsApp->signLink($found['signRequest']) : '',
			'chromeLink' => $checked
				? self::chromeLink($userAgent, $this->urls->linkToRouteAbsolute('libresign.whatsAppSign.page', ['code' => $code]))
				: null,
			// "Sign another document": the upload page, which asks them to sign in first.
			'requestUrl' => $this->urls->linkToRoute('libresign.page.indexFPath', ['path' => 'request']),
			// Asked only where the page shows "Back to WhatsApp" straight away.
			'chatUrl' => $state === 'signed' ? $this->whatsApp->chatUrl() : '',
			'paidBy' => $found['sponsor']?->getDisplayName() ?? '',
			'signatureUrl' => $state === 'ready' && ($found['elements'] ?? []) !== [] ? $this->urls->linkToRoute('libresign.whatsAppSign.signature', ['code' => $code]) : '',
			// Bought here, so they sign without going anywhere else.
			'price' => $state === 'no_credits' ? $this->payments->price() : 0,
			'phone' => $found['signRequest'] !== null ? $this->tuandikiane->phoneFor($found['signRequest']) : '',
			'payUrl' => $this->urls->linkToRoute('libresign.whatsAppSign.pay', ['code' => $code]),
		], TemplateResponse::RENDER_AS_GUEST);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($this->signa->signingOrigin());
		$response->setContentSecurityPolicy($policy);
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * The same hand-off for a page behind the login, such as "View Document" on the
	 * WhatsApp receipt: login.ts sends a browser that can't run a passkey here.
	 * whatsapp-sign.ts asks the browser again, because this is also where Copy link
	 * ends up, and any that can goes straight on to the page.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/open-in-browser')]
	public function openInBrowser(string $to = ''): TemplateResponse {
		if (!self::isHandOffPath($to)) {
			$to = $this->urls->linkToRoute('libresign.page.indexFPath', ['path' => 'request']);
		}
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-whatsapp-sign');
		$userAgent = $this->request->getHeader('User-Agent');
		$response = new TemplateResponse(Application::APP_ID, 'whatsapp_sign', [
			'state' => self::isInAppBrowser($userAgent) ? 'browser' : 'checking',
			'documentName' => '',
			'signUrl' => '',
			'target' => $to,
			'chromeLink' => self::chromeLink($userAgent, $this->urls->getAbsoluteURL($to)),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Whether the login page may hand this path to another browser: our own pages
	 * only, and no "#", ";" or "?", because it ends up inside an intent:// link and
	 * must not be able to add to it.
	 */
	public static function isHandOffPath(string $path): bool {
		return preg_match('#^/apps/libresign/[\w/-]+$#D', $path) === 1;
	}

	/**
	 * A browser whose user agent says it can't run a passkey. Answered here so that the
	 * page opens on the hand-off with no script involved: nothing is prepared, no frame
	 * opens, and a phone holding on to an old script can't start signing. A user agent
	 * that says nothing proves nothing, so passkeyBrowser.ts asks about the rest.
	 *
	 * ponytail: only the tokens real phones have sent. Add one when another turns up.
	 */
	public static function isInAppBrowser(string $userAgent): bool {
		return preg_match('#\[WAiOS/|WA4A/|; wv\)#', $userAgent) === 1;
	}

	/**
	 * The link a phone can use to leave this browser for Chrome, where Google Password
	 * Manager lives, or null where there's none and Copy link is the way out. This only
	 * picks the kind of link.
	 */
	public static function chromeLink(string $userAgent, string $pageUrl): ?string {
		$address = (string)preg_replace('#^https?://#', '', $pageUrl);
		return match (true) {
			str_contains($userAgent, 'Android') => 'intent://' . $address . '#Intent;scheme=https;package=com.android.chrome;end',
			// "[WAiOS/" is for an iPad whose browser reads as a Mac.
			preg_match('#iPhone|iPad|iPod|\[WAiOS/#', $userAgent) === 1 => 'googlechromes://' . $address,
			default => null,
		};
	}

	/**
	 * Twice per signature, like the signing page: without securysignSignature
	 * it prepares the document and answers with SecurySign's approval request;
	 * with it, it writes the signature the frame returned and uses the credit.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/whatsapp/{code}/sign')]
	public function sign(string $code, string $securysignSignature = ''): DataResponse {
		$found = $this->find($code);
		if ($found['state'] !== 'ready') {
			return new DataResponse(['error' => $found['state']], Http::STATUS_CONFLICT);
		}
		/** @var FileEntity $file */
		$file = $found['file'];
		/** @var SignRequest $signRequest */
		$signRequest = $found['signRequest'];
		/** @var IUser $signer */
		$signer = $found['signer'];

		$this->signa->signFor($signer->getUID());
		// The scribbles are files in the signer's own folder, found through the
		// session user on the signing page. This page has no session.
		$this->folders->setUserId($signer->getUID());
		try {
			$this->signFile
				->prepareForSigning(
					$file,
					$signRequest,
					$signer,
					$this->identifyMethods->getUserIdentifier($signRequest->getId()),
					$signRequest->getDisplayName(),
					true,
					null,
					'clickToSign',
				)
				->setVisibleElements($found['elements'])
				->storeUserMetadata($this->metadata->collectMetadata())
				->sign();
		} catch (SecurySignApprovalRequired $e) {
			return new DataResponse(['approval' => $e->approval]);
		} catch (SecurySignUnavailable $e) {
			// Never the local engine here: without the passkey nothing proves it is them.
			$this->logger->warning('SecurySign is unavailable for a WhatsApp signature', ['exception' => $e]);
			return new DataResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			$this->logger->warning('A WhatsApp signature failed', ['exception' => $e, 'signRequestId' => $signRequest->getId()]);
			// LibreSign's own messages are written for the signer ("Wrong passkey…").
			// Anything else can name files and accounts, so the page says it generically.
			$message = $e instanceof LibresignException ? $e->getMessage() : '';
			return new DataResponse(['error' => 'failed', 'message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if ($this->sponsorship()) {
			try {
				$this->settlement->settle(
					signRequestId: $signRequest->getId(),
					signerUserId: $signer->getUID(),
					productCode: ProductCode::SIGN_DOCUMENT->value,
				);
			} catch (\Throwable $e) {
				// The document is signed; a credit that failed to settle is for the log.
				$this->logger->error('A WhatsApp signature could not use its credit', ['exception' => $e, 'signRequestId' => $signRequest->getId()]);
			}
		}
		return new DataResponse(['signed' => true, 'chatUrl' => $this->whatsApp->chatUrl()]);
	}

	/**
	 * What the code leads to, and whether it can be signed now. state: ready,
	 * signed, not_ready (not their turn, or not sent yet), login (no certificate
	 * remembered, or a box and no visible signature scribble: they sign in once
	 * on GoPaperless), no_credits, or gone.
	 *
	 * @return array{state: string, file: ?FileEntity, signRequest: ?SignRequest, signer: ?IUser, sender: ?IUser, elements: ?list<array{documentElementId: int, profileNodeId: int}>}
	 */
	private function find(string $code): array {
		$found = ['state' => 'gone', 'file' => null, 'signRequest' => null, 'signer' => null, 'sender' => null, 'elements' => null, 'sponsor' => null];
		$signRequest = WhatsAppService::on($this->appConfig) ? $this->whatsApp->signRequestForCode($code) : null;
		if ($signRequest === null) {
			return $found;
		}
		try {
			$file = $this->files->getById($signRequest->getFileId());
		} catch (\Throwable) {
			return $found;
		}
		$signer = $this->whatsApp->accountSigner($signRequest);
		if (!$signer instanceof IUser || $file->getNodeType() === 'envelope') {
			return $found;
		}
		$elements = $this->scribbles($signRequest, $signer);
		$open = $signRequest->getSigned() === null && $signRequest->getStatusEnum() === SignRequestStatus::ABLE_TO_SIGN;
		// Someone who never signed in here: MIMI holds their certificate and signature.
		if ($open && ($this->signa->rememberedCertificate($signer->getUID()) === '' || $elements === null) && $this->fromMimi($signer)) {
			$elements = $this->scribbles($signRequest, $signer);
		}
		$coverage = $open && $this->sponsorship() ? $this->coverage->resolveSigningCoverage(
			signRequestId: $signRequest->getId(),
			signerUserId: $signer->getUID(),
			productCode: ProductCode::SIGN_DOCUMENT->value,
		) : null;
		$found = [
			'file' => $file,
			'signRequest' => $signRequest,
			'signer' => $signer,
			'sender' => $this->users->get($file->getUserId()),
			'elements' => $elements,
			// "Paid for by", when someone else's credits cover this signature.
			'sponsor' => $coverage !== null && $coverage->sponsored && $coverage->sponsorUserId !== $signer->getUID()
				? $this->users->get((string)$coverage->sponsorUserId)
				: null,
		] + $found;
		$found['state'] = match (true) {
			$signRequest->getSigned() !== null => 'signed',
			!$open => 'not_ready',
			$this->signa->rememberedCertificate($signer->getUID()) === '', $elements === null => 'login',
			$coverage !== null && !$coverage->allowed => 'no_credits',
			default => 'ready',
		};
		return $found;
	}

	/**
	 * The signer's own visible signature scribble in each of their boxes, as the
	 * signing page sends it. Null when a box needs one they haven't drawn yet:
	 * GoPaperless asks for it when they sign there.
	 *
	 * @return ?list<array{documentElementId: int, profileNodeId: int}>
	 */
	private function scribbles(SignRequest $signRequest, IUser $signer): ?array {
		if (!$this->signerElements->canCreateSignature()) {
			return []; // The stamp is text only.
		}
		$elements = [];
		foreach ($this->fileElements->getByFileIdAndSignRequestId($signRequest->getFileId(), $signRequest->getId()) as $box) {
			try {
				$scribble = $this->userElements->findOne(['user_id' => $signer->getUID(), 'type' => $box->getType()]);
			} catch (\Throwable) {
				return null;
			}
			$elements[] = ['documentElementId' => $box->getId(), 'profileNodeId' => $scribble->getNodeId()];
		}
		return $elements;
	}

	/** True when MIMI gave this account what signing here needs. Never throws. */
	private function fromMimi(IUser $signer): bool {
		$sub = $this->whatsApp->subFor($signer);
		try {
			return $sub !== null && $this->signa->accountFor($sub) !== null;
		} catch (\Throwable $e) {
			$this->logger->info('MIMI could not fill in a WhatsApp signer', ['exception' => $e]);
			return false;
		}
	}

	/** The signature the card is about to place, shown above SecurySign's frame. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/whatsapp/{code}/signature')]
	public function signature(string $code): DataDisplayResponse|DataResponse {
		$found = $this->find($code);
		$element = $found['state'] === 'ready' ? ($found['elements'][0] ?? null) : null;
		if ($element === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$this->folders->setUserId($found['signer']->getUID());
		try {
			$png = $this->folders->getFileByNodeId($element['profileNodeId'])->getContent();
		} catch (\Throwable) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($png, Http::STATUS_OK, ['Content-Type' => 'image/png']);
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * One signature, bought by the signer on the card. M-Pesa prompts the
	 * number they give; a card goes through DPO and comes back here.
	 */
	#[PublicPage]
	#[AnonRateLimit(limit: 6, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/whatsapp/{code}/pay')]
	public function pay(string $code, string $method = 'mobile', string $phone = ''): DataResponse {
		$found = $this->find($code);
		if ($found['state'] !== 'no_credits') {
			return new DataResponse(['status' => $found['state'] === 'ready' ? 'SUCCESS' : 'FAILED']);
		}
		try {
			return new DataResponse($this->payments->start(
				$found['signer'], 1, $method, $phone,
				$this->urls->linkToRouteAbsolute('libresign.whatsAppSign.page', ['code' => $code]),
			));
		} catch (\Throwable $e) {
			$this->logger->warning('A WhatsApp signing payment did not start', ['exception' => $e]);
			return new DataResponse(['error' => 'failed'], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/whatsapp/{code}/pay/{reference}')]
	public function payStatus(string $code, string $reference): DataResponse {
		$found = $this->find($code);
		if (!$found['signer'] instanceof IUser) {
			return new DataResponse(['error' => 'gone'], Http::STATUS_GONE);
		}
		try {
			return new DataResponse(['status' => $this->payments->status($found['signer'], $reference)]);
		} catch (\Throwable) {
			return new DataResponse(['error' => 'unknown'], Http::STATUS_NOT_FOUND);
		}
	}

	/** Credits apply only with sponsorship on, as on the signing page. */
	private function sponsorship(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'sponsorship_enabled', false);
	}
}
