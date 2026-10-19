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
use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Exception\SecurySignApprovalRequired;
use OCA\Libresign\Exception\SecurySignUnavailable;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\RequestMetadataService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\SignFileService;
use OCA\Libresign\Service\Sponsorship\SigningCoverageService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCA\Libresign\Service\WhatsAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
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
		private IdentifyMethodService $identifyMethods,
		private RequestMetadataService $metadata,
		private SigningCoverageService $coverage,
		private SigningSettlementService $settlement,
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
	public function page(string $code): TemplateResponse {
		$found = $this->find($code);
		$state = $found['state'];
		// WhatsApp's own browser on iPhone (user agent ends in "[WAiOS/<version>]")
		// can't use a passkey: iOS keeps passkeys in an app's web view to that
		// app's own domains. The same page in Safari can.
		if ($state === 'ready' && str_contains($this->request->getHeader('User-Agent'), '[WAiOS/')) {
			$state = 'safari';
		}
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		if ($state === 'ready' || $state === 'safari') {
			Util::addScript(Application::APP_ID, 'libresign-whatsapp-sign');
		}
		$response = new TemplateResponse(Application::APP_ID, 'whatsapp_sign', [
			'state' => $state,
			'documentName' => $found['file']?->getName() ?? '',
			'senderName' => $found['sender']?->getDisplayName() ?? '',
			'signUrl' => $this->urls->linkToRoute('libresign.whatsAppSign.sign', ['code' => $code]),
			'signLink' => $found['signRequest'] !== null ? $this->whatsApp->signLink($found['signRequest']) : '',
			// iOS 17+ opens x-safari-https:// links in Safari, out of the app's browser.
			'safariUrl' => 'x-safari-' . $this->urls->linkToRouteAbsolute('libresign.whatsAppSign.page', ['code' => $code]),
		], TemplateResponse::RENDER_AS_GUEST);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($this->signa->signingOrigin());
		$response->setContentSecurityPolicy($policy);
		$response->cacheFor(0);
		return $response;
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
		// Each box on the document: the server fills in the signer's own scribble.
		$elements = array_map(
			static fn ($element) => ['documentElementId' => $element->getId()],
			$this->fileElements->getByFileIdAndSignRequestId($signRequest->getFileId(), $signRequest->getId()),
		);
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
				->setVisibleElements($elements)
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
			return new DataResponse(['error' => 'failed', 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
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
		return new DataResponse(['signed' => true]);
	}

	/**
	 * What the code leads to, and whether it can be signed now. state: ready,
	 * signed, not_ready (not their turn, or not sent yet), login (no certificate
	 * remembered: they sign in once on GoPaperless), no_credits, or gone.
	 *
	 * @return array{state: string, file: ?FileEntity, signRequest: ?SignRequest, signer: ?IUser, sender: ?IUser}
	 */
	private function find(string $code): array {
		$found = ['state' => 'gone', 'file' => null, 'signRequest' => null, 'signer' => null, 'sender' => null];
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
		$found = ['file' => $file, 'signRequest' => $signRequest, 'signer' => $signer, 'sender' => $this->users->get($file->getUserId())] + $found;
		$found['state'] = match (true) {
			$signRequest->getSigned() !== null => 'signed',
			$signRequest->getStatusEnum() !== SignRequestStatus::ABLE_TO_SIGN => 'not_ready',
			$this->signa->rememberedCertificate($signer->getUID()) === '' => 'login',
			$this->sponsorship() && !$this->coverage->resolveSigningCoverage(
				signRequestId: $signRequest->getId(),
				signerUserId: $signer->getUID(),
				productCode: ProductCode::SIGN_DOCUMENT->value,
			)->allowed => 'no_credits',
			default => 'ready',
		};
		return $found;
	}

	/** Credits apply only with sponsorship on, as on the signing page. */
	private function sponsorship(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'sponsorship_enabled', false);
	}
}
