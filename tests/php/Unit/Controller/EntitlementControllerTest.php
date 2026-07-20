<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\EntitlementController;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Sponsorship\DTO\SigningCoverageResolutionDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSponsorshipDTO;
use OCA\Libresign\Service\Sponsorship\SigningCoverageService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class EntitlementControllerTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private IRequest&MockObject $request;
	private EntitlementService&MockObject $entitlementService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private SigningCoverageService&MockObject $signingCoverageService;
	private SigningSettlementService&MockObject $signingSettlementService;
	private ValidateHelper&MockObject $validateHelper;
	private SignRequestMapper&MockObject $signRequestMapper;
	private IAppConfig&MockObject $appConfig;
	private IUser&MockObject $user;

	public function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->entitlementService = $this->createMock(EntitlementService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->signingCoverageService = $this->createMock(SigningCoverageService::class);
		$this->signingSettlementService = $this->createMock(SigningSettlementService::class);
		$this->validateHelper = $this->createMock(ValidateHelper::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('signer');
	}

	private function getController(): EntitlementController {
		return new EntitlementController(
			$this->request,
			$this->entitlementService,
			$this->userSession,
			$this->logger,
			$this->signingCoverageService,
			$this->signingSettlementService,
			$this->validateHelper,
			$this->signRequestMapper,
			$this->appConfig,
		);
	}

	private function enableSponsorship(): void {
		$this->appConfig
			->method('getValueBool')
			->with('libresign', 'sponsorship_enabled', false)
			->willReturn(true);
	}

	public function testSponsorshipReturnsUnauthorizedWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->sponsorship('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['allowed']);
	}

	public function testSponsorshipReturnsForbiddenWhenFeatureDisabled(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->appConfig
			->method('getValueBool')
			->with('libresign', 'sponsorship_enabled', false)
			->willReturn(false);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->sponsorship('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success'] ?? true);
	}

	public function testSponsorshipReturnsNotFoundForMissingSignRequest(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$this->validateHelper
			->method('validateSignRequestBelongsToUser')
			->willThrowException(new DoesNotExistException('Sign request not found'));
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->sponsorship('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['allowed']);
	}

	public function testSponsorshipReturnsForbiddenWhenNotAssigned(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$this->validateHelper
			->method('validateSignRequestBelongsToUser')
			->willThrowException(new LibresignException('Sign request is not assigned to this user'));
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->sponsorship('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testSponsorshipReturnsCoverageDtoWhenAuthorized(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$this->validateHelper
			->expects($this->once())
			->method('validateSignRequestBelongsToUser')
			->with(1, 'signer');
		$this->signingCoverageService
			->method('resolveSigningSponsorship')
			->with(1)
			->willReturn(new SigningSponsorshipDTO(\OCA\Libresign\Enum\SponsorshipType::REQUESTER, 'requester'));
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->sponsorship('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('requester', $response->getData()['sponsorUserId']);
	}

	public function testCheckReturnsForbiddenWhenFeatureDisabled(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->appConfig
			->method('getValueBool')
			->with('libresign', 'sponsorship_enabled', false)
			->willReturn(false);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->check('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCheckReturnsForbiddenWhenNotAssigned(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$this->validateHelper
			->method('validateSignRequestBelongsToUser')
			->willThrowException(new LibresignException('Sign request is not assigned to this user'));
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->check('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCheckReturnsCoverageWhenAuthorized(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$this->validateHelper
			->expects($this->once())
			->method('validateSignRequestBelongsToUser')
			->with(1, 'signer');
		$this->signingCoverageService
			->method('resolveSigningCoverage')
			->with('signer', 1, 'SIGN_DOCUMENT')
			->willReturn(new SigningCoverageResolutionDTO(allowed: true, sponsored: false, sponsorUserId: null));
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->check('SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['allowed']);
	}

	public function testConsumeAfterSignReturnsUnauthorizedWhenSessionMismatch(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->consumeAfterSign('other', 'uuid', 'SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testConsumeAfterSignReturnsForbiddenWhenFeatureDisabled(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->appConfig
			->method('getValueBool')
			->with('libresign', 'sponsorship_enabled', false)
			->willReturn(false);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->consumeAfterSign('signer', 'uuid', 'SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testConsumeAfterSignReturnsForbiddenWhenSignUuidMismatch(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$signRequest = $this->createMock(SignRequest::class);
		$signRequest->method('getUuid')->willReturn('correct-uuid');
		$this->signRequestMapper
			->method('getById')
			->with(1)
			->willReturn($signRequest);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->consumeAfterSign('signer', 'wrong-uuid', 'SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testConsumeAfterSignReturnsForbiddenWhenNotAssigned(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$signRequest = $this->createMock(SignRequest::class);
		$signRequest->method('getUuid')->willReturn('uuid');
		$this->signRequestMapper
			->method('getById')
			->with(1)
			->willReturn($signRequest);
		$this->validateHelper
			->method('validateSignRequestBelongsToUser')
			->willThrowException(new LibresignException('Sign request is not assigned to this user'));
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->consumeAfterSign('signer', 'uuid', 'SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testConsumeAfterSignReturnsSuccessWhenAuthorized(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->enableSponsorship();
		$signRequest = $this->createMock(SignRequest::class);
		$signRequest->method('getUuid')->willReturn('uuid');
		$this->signRequestMapper
			->method('getById')
			->with(1)
			->willReturn($signRequest);
		$this->validateHelper
			->expects($this->once())
			->method('validateSignRequestBelongsToUser')
			->with(1, 'signer');
		$entitlement = new \OCA\Libresign\Db\Entitlement();
		$entitlement->setId(10);
		$entitlement->setRemainingUses(5);
		$this->signingSettlementService
			->method('settle')
			->with(1, 'signer', 'SIGN_DOCUMENT')
			->willReturn($entitlement);
		$controller = $this->getController();

		/** @var DataResponse $response */
		$response = $controller->consumeAfterSign('signer', 'uuid', 'SIGN_DOCUMENT', 1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(5, $response->getData()['remainingUses']);
	}
}
