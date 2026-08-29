<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Db\PhoneMnoCache;
use OCA\Libresign\Db\PhoneMnoCacheMapper;
use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PhoneMnoResolutionSource;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\PaymentPhoneResolutionDTO;
use OCA\Libresign\Service\Payment\MnoDetectionRegistry;
use OCA\Libresign\Service\Payment\MnoRoutingRegistry;
use OCA\Libresign\Service\Payment\PaymentCountryResolver;
use OCA\Libresign\Service\Payment\PaymentDateTimeHelper;
use OCA\Libresign\Service\Payment\PhoneMnoResolver;
use OCA\Libresign\Service\Payment\PhoneResolutionService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Behavioural contracts for the override > cache > detection > fallback
 * resolution pipeline. Detection, country and routing use the REAL registries
 * so the identity -> route chain is exercised end to end.
 *
 * resolve() returns PhoneMnoResolutionDTO { identity, providerOverride }:
 * identity assertions use ->identity->carrier etc.; the explicit admin rail
 * uses ->providerOverride. The two must never collapse into one another.
 */
final class PhoneMnoResolverTest extends TestCase {
	private PhoneMnoOverrideMapper&MockObject $overrideMapper;
	private PhoneMnoCacheMapper&MockObject $cacheMapper;
	private PhoneResolutionService&MockObject $phoneResolution;
	private PaymentDateTimeHelper&MockObject $dateTimeHelper;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private \DateTimeImmutable $now;
	private PhoneMnoResolver $resolver;

	public function setUp(): void {
		parent::setUp();

		$this->overrideMapper = $this->createMock(PhoneMnoOverrideMapper::class);
		$this->cacheMapper = $this->createMock(PhoneMnoCacheMapper::class);
		$this->phoneResolution = $this->createMock(PhoneResolutionService::class);
		$this->dateTimeHelper = $this->createMock(PaymentDateTimeHelper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->now = new \DateTimeImmutable('2026-08-29T12:00:00+00:00');
		$this->dateTimeHelper->method('nowImmutable')->willReturn($this->now);
		$this->appConfig->method('getValueInt')->willReturn(3600);

		$this->resolver = new PhoneMnoResolver(
			$this->overrideMapper,
			$this->cacheMapper,
			$this->phoneResolution,
			new MnoDetectionRegistry(),
			new PaymentCountryResolver(),
			$this->dateTimeHelper,
			$this->appConfig,
			$this->logger,
		);
	}

	private function base(string $region, string $national, ?string $carrierHint = null): PaymentPhoneResolutionDTO {
		return new PaymentPhoneResolutionDTO(
			valid: true,
			e164: '+254' . $national,
			e164Digits: '254' . $national,
			national: $national,
			region: $region,
			carrierHint: $carrierHint,
			countryCallingCode: '254',
		);
	}

	private function cacheRow(string $mno, string $confidence, string $version, \DateTime $resolvedAt): PhoneMnoCache {
		$row = new PhoneMnoCache();
		$row->setPhoneE164Digits('+254712345678');
		$row->setRegion('KE');
		$row->setCountry('kenya');
		$row->setMno($mno);
		$row->setConfidence($confidence);
		$row->setResolverVersion($version);
		$row->setResolvedAt($resolvedAt);

		return $row;
	}

	private function override(string $mno, string $provider = 'daraja'): PhoneMnoOverride {
		$o = new PhoneMnoOverride();
		$o->setPhoneE164Digits('+254712345678');
		$o->setMno($mno);
		$o->setProvider($provider);
		$o->setActive(true);

		return $o;
	}

	private function route(string $region, ?string $carrier, ResolutionConfidence $confidence): PaymentProvider {
		return (new MnoRoutingRegistry())->route(
			PaymentCapability::MOBILE_MONEY,
			'kenya',
			$region,
			$carrier,
			$confidence,
		)->preferredProvider;
	}

	public function testNormalSafaricomResolvesAndRoutesToDaraja(): void {
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '712345678', 'Safaricom'));

		$result = $this->resolver->resolve('+254712345678');
		$identity = $result->identity;

		self::assertTrue($identity->valid);
		self::assertSame('mpesa', $identity->carrier);
		self::assertSame(ResolutionConfidence::HIGH, $identity->confidence);
		self::assertSame(PhoneMnoResolutionSource::DETECTION, $identity->source);
		self::assertNull($result->providerOverride, 'detection must not carry a provider override');
		self::assertSame(PaymentProvider::DARAJA, $this->route($identity->region, $identity->carrier, $identity->confidence));
	}

	public function testNormalAirtelResolvesAndRoutesToDpo(): void {
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));

		$identity = $this->resolver->resolve('+254730123456')->identity;

		self::assertSame('airtel', $identity->carrier);
		self::assertSame(PaymentProvider::DPO, $this->route($identity->region, $identity->carrier, $identity->confidence));
	}

	public function testCacheHitShortCircuitsDetection(): void {
		// National number is an Airtel prefix, but the cache says mpesa.
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));
		$this->cacheMapper->method('findByPhone')->willReturn(
			$this->cacheRow('mpesa', 'high', PhoneMnoResolver::RESOLVER_VERSION, new \DateTime('2026-08-29T11:58:20+00:00'))
		);

		$result = $this->resolver->resolve('+254730123456');

		self::assertSame('mpesa', $result->identity->carrier, 'cache must win over prefix detection');
		self::assertSame(PhoneMnoResolutionSource::CACHE, $result->identity->source);
		self::assertNull($result->providerOverride, 'cache must not carry a provider override');
	}

	public function testCacheMissPopulatesCache(): void {
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '712345678', 'Safaricom'));
		$this->cacheMapper->method('findByPhone')->willReturn(null);

		$this->cacheMapper->expects($this->once())
			->method('store')
			->with(
				'+254712345678',
				'KE',
				'kenya',
				'mpesa',
				'Safaricom',
				'high',
				PhoneMnoResolver::RESOLVER_VERSION,
				$this->anything(),
			)
			->willReturn($this->cacheRow('mpesa', 'high', PhoneMnoResolver::RESOLVER_VERSION, new \DateTime()));

		$identity = $this->resolver->resolve('+254712345678')->identity;

		self::assertSame('mpesa', $identity->carrier);
	}

	public function testActiveOverrideBeatsConflictingCache(): void {
		$this->overrideMapper->method('findActiveByPhone')->willReturn($this->override('safaricom'));
		$this->cacheMapper->method('findByPhone')->willReturn(
			$this->cacheRow('airtel', 'high', PhoneMnoResolver::RESOLVER_VERSION, new \DateTime('2026-08-29T11:59:00+00:00'))
		);
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));

		$identity = $this->resolver->resolve('+254712345678')->identity;

		self::assertSame('safaricom', $identity->carrier);
		self::assertSame(PhoneMnoResolutionSource::OVERRIDE, $identity->source);
		self::assertSame(PaymentProvider::DARAJA, $this->route($identity->region, $identity->carrier, $identity->confidence));
	}

	public function testActiveOverrideBeatsDetection(): void {
		// Detection would say airtel; the override forces safaricom.
		$this->overrideMapper->method('findActiveByPhone')->willReturn($this->override('safaricom'));
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));

		$identity = $this->resolver->resolve('+254730123456')->identity;

		self::assertSame('safaricom', $identity->carrier);
		self::assertSame(PhoneMnoResolutionSource::OVERRIDE, $identity->source);
	}

	public function testInactiveOverrideFallsThroughToDetection(): void {
		// findActiveByPhone only returns ACTIVE rows; an inactive override => null.
		$this->overrideMapper->method('findActiveByPhone')->willReturn(null);
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));

		$result = $this->resolver->resolve('+254730123456');

		self::assertSame('airtel', $result->identity->carrier);
		self::assertSame(PhoneMnoResolutionSource::DETECTION, $result->identity->source);
		self::assertNull($result->providerOverride);
	}

	public function testExplicitProviderOverrideIsReturned(): void {
		// Admin: safaricom + DPO (a valid cross-provider combination).
		$this->overrideMapper->method('findActiveByPhone')->willReturn($this->override('safaricom', 'dpo'));
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '712345678', 'Safaricom'));

		$result = $this->resolver->resolve('+254712345678');

		self::assertSame('safaricom', $result->identity->carrier, 'identity stays canonical');
		self::assertSame(PaymentProvider::DPO, $result->providerOverride, 'explicit rail is surfaced separately');
	}

	public function testInvalidOverrideProviderYieldsNullProviderOverride(): void {
		$this->overrideMapper->method('findActiveByPhone')->willReturn($this->override('safaricom', 'bogus'));
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '712345678', 'Safaricom'));

		$result = $this->resolver->resolve('+254712345678');

		self::assertTrue($result->identity->valid);
		self::assertSame('safaricom', $result->identity->carrier);
		self::assertNull($result->providerOverride, 'unparseable provider must not become a rail');
	}

	public function testOverrideResolvesNumberDetectionCannotIdentify(): void {
		// 999xxxxx matches no KE prefix -> detection UNKNOWN, no carrier hint.
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '999999999'));

		$withoutOverride = $this->resolver->resolve('+254999999999')->identity;
		self::assertNull($withoutOverride->carrier, 'no override: undetectable number has no carrier');
		self::assertNotSame(PaymentProvider::DARAJA, $this->route($withoutOverride->region, $withoutOverride->carrier, $withoutOverride->confidence));

		$this->overrideMapper->method('findActiveByPhone')->willReturn($this->override('safaricom'));
		$withOverride = $this->resolver->resolve('+254999999999')->identity;

		self::assertSame('safaricom', $withOverride->carrier);
		self::assertSame(PaymentProvider::DARAJA, $this->route($withOverride->region, $withOverride->carrier, $withOverride->confidence));
	}

	public function testOverrideRescuesLibphonenumberInvalidNumber(): void {
		// Strict resolve rejects it (simulates the isValidNumber gate / DTO invariant)...
		$this->phoneResolution->method('resolve')->willThrowException(new \InvalidArgumentException('invalid'));
		// ...but a lenient parse still recovers geo, and the override supplies the MNO.
		$this->phoneResolution->method('parseLenient')->willReturn([
			'region' => 'KE',
			'national' => '712345678',
			'e164' => '+254712345678',
			'carrierHint' => null,
		]);
		$this->overrideMapper->method('findActiveByPhone')->willReturn($this->override('safaricom'));

		$identity = $this->resolver->resolve('+254112345678')->identity;

		self::assertTrue($identity->valid, 'override must rescue an otherwise-invalid number');
		self::assertSame('KE', $identity->region);
		self::assertSame('safaricom', $identity->carrier);
		self::assertSame(PaymentProvider::DARAJA, $this->route($identity->region, $identity->carrier, $identity->confidence));
	}

	public function testExpiredCacheIsTreatedAsMiss(): void {
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));
		// resolvedAt well beyond the 3600s TTL.
		$this->cacheMapper->method('findByPhone')->willReturn(
			$this->cacheRow('mpesa', 'high', PhoneMnoResolver::RESOLVER_VERSION, new \DateTime('2026-08-28T00:00:00+00:00'))
		);

		$identity = $this->resolver->resolve('+254730123456')->identity;

		self::assertSame('airtel', $identity->carrier, 'stale cache must fall through to detection');
		self::assertSame(PhoneMnoResolutionSource::DETECTION, $identity->source);
	}

	public function testResolverVersionMismatchIsTreatedAsMiss(): void {
		$this->phoneResolution->method('resolve')->willReturn($this->base('KE', '730123456', 'Airtel'));
		// Fresh timestamp, but stamped with an old resolver version.
		$this->cacheMapper->method('findByPhone')->willReturn(
			$this->cacheRow('mpesa', 'high', 'old-version', new \DateTime('2026-08-29T11:59:50+00:00'))
		);

		$identity = $this->resolver->resolve('+254730123456')->identity;

		self::assertSame('airtel', $identity->carrier, 'version mismatch must fall through to detection');
		self::assertSame(PhoneMnoResolutionSource::DETECTION, $identity->source);
	}

	public function testRememberResolvedMnoWritesConfirmedIdentity(): void {
		$this->cacheMapper->expects($this->once())
			->method('store')
			->with(
				'+254733111222',
				'KE',
				'kenya',
				'airtel',
				null,
				'high',
				PhoneMnoResolver::RESOLVER_VERSION,
				$this->anything(),
			)
			->willReturn($this->cacheRow('airtel', 'high', PhoneMnoResolver::RESOLVER_VERSION, new \DateTime()));

		$this->resolver->rememberResolvedMno('+254733111222', 'KE', 'kenya', 'Airtel');
	}

	public function testNormalizePhoneKeyMatchesStoredRepresentation(): void {
		self::assertSame('+254712345678', PhoneMnoResolver::normalizePhoneKey('+254 712 345 678'));
		self::assertSame('+254712345678', PhoneMnoResolver::normalizePhoneKey('+254-712-345-678'));
		self::assertNull(PhoneMnoResolver::normalizePhoneKey('   '));
		self::assertNull(PhoneMnoResolver::normalizePhoneKey(null));
	}
}
