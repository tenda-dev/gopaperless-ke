<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics;

use OCA\Libresign\Service\Operations\Analytics\FundingClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FundingClassifierTest extends TestCase {
	#[DataProvider('metadataProvider')]
	public function testSettlementReadsMetadataTheWaySettlementDoes(mixed $raw, bool $consumed, ?int $entitlementId): void {
		$this->assertSame(
			['consumed' => $consumed, 'entitlementId' => $entitlementId],
			FundingClassifier::settlement($raw),
		);
	}

	/** @return iterable<string, array{mixed, bool, ?int}> */
	public static function metadataProvider(): iterable {
		yield 'settled' => ['{"entitlement_consumed":true,"productCode":"SIGN_DOCUMENT","entitlementId":42}', true, 42];
		yield 'already-decoded array' => [['entitlement_consumed' => true, 'entitlementId' => 7], true, 7];
		yield 'numeric-string id' => ['{"entitlement_consumed":true,"entitlementId":"15"}', true, 15];
		yield 'marker without entitlementId (legacy writer)' => ['{"entitlement_consumed":true,"productCode":"SIGN_DOCUMENT"}', true, null];
		yield 'entitlementId without marker is NOT consumption' => ['{"entitlementId":42}', false, 42];
		yield 'marker false' => ['{"entitlement_consumed":false,"entitlementId":42}', false, 42];
		yield 'unrelated metadata' => ['{"d":[{"k":"v"}],"p":{"x":1}}', false, null];
		yield 'null' => [null, false, null];
		yield 'empty string' => ['', false, null];
		yield 'malformed json' => ['{"entitlement_consumed":tru', false, null];
		yield 'json scalar' => ['true', false, null];
		yield 'json list' => ['[1,2,3]', false, null];
		yield 'zero id' => ['{"entitlement_consumed":true,"entitlementId":0}', true, null];
		yield 'negative id' => ['{"entitlement_consumed":true,"entitlementId":-3}', true, null];
		yield 'non-numeric id' => ['{"entitlement_consumed":true,"entitlementId":"abc"}', true, null];
		yield 'float id' => ['{"entitlement_consumed":true,"entitlementId":4.5}', true, null];
	}

	#[DataProvider('classifyProvider')]
	public function testClassify(bool $consumed, string $sponsorshipType, bool $paid, string $expected): void {
		$this->assertSame($expected, FundingClassifier::classify($consumed, $sponsorshipType, $paid));
	}

	/** @return iterable<string, array{bool, string, bool, string}> */
	public static function classifyProvider(): iterable {
		yield 'requester + marker' => [true, 'requester', false, FundingClassifier::REQUESTER_SPONSORED];
		yield 'self + marker' => [true, 'self', false, FundingClassifier::SELF_ENTITLEMENT];
		yield 'marker beats a paid payment' => [true, 'self', true, FundingClassifier::SELF_ENTITLEMENT];
		yield 'direct pay: no marker, paid' => [false, 'self', true, FundingClassifier::DIRECT_PAY];
		// Trap A: the column DEFAULT 'self' is not evidence of self-funded consumption.
		yield 'legacy self, no marker, no payment' => [false, 'self', false, FundingClassifier::UNKNOWN];
		// requester is a positive signal for sponsorship, but without the marker nothing was consumed.
		yield 'requester intent, never settled' => [false, 'requester', false, FundingClassifier::UNKNOWN];
		yield 'requester intent, paid directly' => [false, 'requester', true, FundingClassifier::DIRECT_PAY];
	}
}
