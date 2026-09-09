<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Middleware;

use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Controller\SignatureElementsController;
use OCA\Libresign\Controller\SignFileController;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Middleware\SecurySignMiddleware;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class SecurySignMiddlewareTest extends TestCase {
	private SecurySignService&MockObject $signa;
	private IRequest&MockObject $request;
	private SecurySignMiddleware $middleware;

	protected function setUp(): void {
		$this->signa = $this->createMock(SecurySignService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRequestUri')->willReturn('/apps/libresign/f/document');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => $route === 'libresign.securySign.onboard'
				? '/apps/libresign/securysign/onboard?returnTo=' . rawurlencode($params['returnTo'])
				: '/' . str_replace('.', '/', $route) . '?' . http_build_query($params),
		);
		$urls->method('linkToDefaultPageUrl')->willReturn('/apps/dashboard/');
		$this->middleware = new SecurySignMiddleware($this->signa, $this->request, $urls, $this->createMock(LoggerInterface::class));
	}

	private function page(): TemplateResponse {
		return new TemplateResponse('libresign', 'main');
	}

	public function testAnUnpreparedUserIsSentIntoOnboardingWithTheirTaskAttached(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('readiness')->willReturn(null);

		$response = $this->middleware->afterController($this->createMock(PageController::class), 'index', $this->page());

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(
			'/apps/libresign/securysign/onboard?returnTo=%2Fapps%2Flibresign%2Ff%2Fdocument',
			$response->getRedirectURL(),
		);
	}

	public function testAPreparedUserAndAnEmailPasswordUserBothSeeTheAppUnchanged(): void {
		$this->signa->method('applies')->willReturnOnConsecutiveCalls(true, false);
		$this->signa->method('readiness')->willReturn(['certificateId' => '7', 'imagePngBase64' => 'cGVuZw==']);
		// A prepared user still gets their SecurySign card mirrored locally.
		$this->signa->expects(self::once())->method('syncVisibleSignature')->with(['certificateId' => '7', 'imagePngBase64' => 'cGVuZw==']);
		$page = $this->page();

		self::assertSame($page, $this->middleware->afterController($this->createMock(PageController::class), 'index', $page));
		self::assertSame($page, $this->middleware->afterController($this->createMock(PageController::class), 'index', $page));
	}

	public function testAnOutageDoesNotSilentlyLetTheUserThrough(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('readiness')->willThrowException(new \RuntimeException('down', 503));

		$response = $this->middleware->afterController($this->createMock(PageController::class), 'index', $this->page());

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(503, $response->getStatus());
	}

	public function testTheSigningApiCannotBeUsedToSkipOnboarding(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willReturn(false);

		try {
			$this->middleware->beforeController($this->createMock(SignFileController::class), 'signByFileId');
			self::fail('Signing was allowed before SecurySign was ready');
		} catch (LibresignException $e) {
			self::assertSame(403, $e->getCode());
		}
	}

	public function testTheSigningApiReportsAnOutageInsteadOfSigning(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willThrowException(new \RuntimeException('down', 503));

		try {
			$this->middleware->beforeController($this->createMock(SignFileController::class), 'signBySignerUuid');
			self::fail('Signing was allowed during a SecurySign outage');
		} catch (LibresignException $e) {
			self::assertSame(503, $e->getCode());
		}
	}

	public function testTokenSignersAndOtherEndpointsAreNotGated(): void {
		$this->signa->method('applies')->willReturn(false);
		$this->signa->expects(self::never())->method('isReady');

		$this->middleware->beforeController($this->createMock(SignFileController::class), 'signByFileId');

		$ready = $this->createMock(SecurySignService::class);
		$ready->method('applies')->willReturn(true);
		$ready->expects(self::never())->method('isReady');
		$other = new SecurySignMiddleware($ready, $this->request, $this->createMock(IURLGenerator::class), $this->createMock(LoggerInterface::class));
		$other->beforeController($this->createMock(SignFileController::class), 'requestCodeByFileId');
	}

	/**
	 * Both failures land on the branded guest page rather than a bare wall of text,
	 * and both carry a way forward: a rejected session offers the force=1 handoff
	 * that signs the user out and straight back in, an outage offers a retry, and
	 * either way there is a link out of LibreSign entirely.
	 */
	public function testEveryFailureIsBrandedAndOffersAWayOut(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('providerId')->willReturn(2);

		$cases = [
			[401, 401, 'libresign/sso/handoff', 'force=1'],
			[503, 503, 'apps/libresign/f/document', ''],
		];
		foreach ($cases as [$thrown, $expected, $needle, $extra]) {
			$signa = $this->createMock(SecurySignService::class);
			$signa->method('applies')->willReturn(true);
			$signa->method('providerId')->willReturn(2);
			$signa->method('readiness')->willThrowException(new \RuntimeException('upstream said no', $thrown));
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRoute')->willReturnCallback(
				static fn (string $route, array $params = []): string => '/' . str_replace('.', '/', $route) . '?' . http_build_query($params),
			);
			$urls->method('linkToDefaultPageUrl')->willReturn('/apps/dashboard/');
			$middleware = new SecurySignMiddleware($signa, $this->request, $urls, $this->createMock(LoggerInterface::class));

			$response = $middleware->afterController($this->createMock(PageController::class), 'index', $this->page());

			self::assertInstanceOf(TemplateResponse::class, $response);
			self::assertSame($expected, $response->getStatus());
			self::assertSame('securysign_notice', $response->getTemplateName());
			$params = $response->getParams();
			self::assertNotSame('', $params['title']);
			self::assertStringNotContainsString('upstream said no', $params['message'], 'the upstream text is for the log, not the user');
			self::assertStringContainsString($needle, $params['actionUrl']);
			if ($extra !== '') {
				self::assertStringContainsString($extra, $params['actionUrl']);
			}
			self::assertSame('/apps/dashboard/', $params['secondaryUrl']);
		}
	}
	/**
	 * SecurySign owns the card, so the endpoints that would replace it are
	 * refused. Hiding the button in the Vue would leave the API open, and the API
	 * is what actually decides what lands on a document. Reads stay open: the
	 * mirrored signature has to render.
	 */
	public function testTheSignatureCannotBeReplacedThroughTheApi(): void {
		$this->signa->method('applies')->willReturn(true);
		$controller = $this->createMock(SignatureElementsController::class);

		foreach (['createSignatureElement', 'patchSignatureElement', 'deleteSignatureElement'] as $method) {
			try {
				$this->middleware->beforeController($controller, $method);
				self::fail($method . ' was allowed');
			} catch (LibresignException $e) {
				self::assertSame(403, $e->getCode());
				self::assertStringContainsString('SecurySign', $e->getMessage());
			}
		}

		// Reading them must still work, or the mirrored card cannot be shown.
		$this->middleware->beforeController($controller, 'getSignatureElements');
		$this->middleware->beforeController($controller, 'getSignatureElementPreview');

		// And an email/password user keeps full control of their own signature.
		$other = $this->createMock(SecurySignService::class);
		$other->method('applies')->willReturn(false);
		$middleware = new SecurySignMiddleware($other, $this->request, $this->createMock(IURLGenerator::class), $this->createMock(LoggerInterface::class));
		$middleware->beforeController($controller, 'createSignatureElement');
	}
}
