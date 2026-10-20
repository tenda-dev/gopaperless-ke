<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Db;

use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Tests\Unit\TestCase;

/**
 * Entity-level coverage for the claim schema: safe legacy defaults, nullable
 * claim fields, the self-signer boolean, and the lifecycle status constants.
 */
final class PublicWorkflowTest extends TestCase {
	public function testLegacyDefaultsAreSafe(): void {
		$workflow = new PublicWorkflow();
		// A row created before Step 2 (or a fresh insert) must default to a safe,
		// non-claimed, non-signer state with no spurious timestamps/emails.
		$this->assertSame(PublicWorkflow::STATUS_ACTIVE, $workflow->getStatus());
		$this->assertFalse($workflow->getRequesterIsSigner());
		$this->assertNull($workflow->getRequesterEmail());
		$this->assertNull($workflow->getClaimedByUid());
		$this->assertNull($workflow->getClaimStartedAt());
		$this->assertNull($workflow->getClaimedAt());
		$this->assertNull($workflow->getUpdatedAt());
	}

	public function testClaimFieldsRoundTrip(): void {
		$workflow = new PublicWorkflow();
		$started = new \DateTime('2026-10-19T00:00:00+00:00');
		$claimed = new \DateTime('2026-10-19T00:00:05+00:00');

		$workflow->setRequesterEmail('requester@example.com');
		$workflow->setRequesterIsSigner(true);
		$workflow->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$workflow->setClaimedByUid('alice');
		$workflow->setClaimStartedAt($started);
		$workflow->setClaimedAt($claimed);

		$this->assertSame('requester@example.com', $workflow->getRequesterEmail());
		$this->assertTrue($workflow->getRequesterIsSigner());
		$this->assertSame(PublicWorkflow::STATUS_CLAIMING, $workflow->getStatus());
		$this->assertSame('alice', $workflow->getClaimedByUid());
		$this->assertSame($started, $workflow->getClaimStartedAt());
		$this->assertSame($claimed, $workflow->getClaimedAt());
	}

	public function testLifecycleStatusConstants(): void {
		$this->assertSame('active', PublicWorkflow::STATUS_ACTIVE);
		$this->assertSame('claiming', PublicWorkflow::STATUS_CLAIMING);
		$this->assertSame('claimed', PublicWorkflow::STATUS_CLAIMED);
		$this->assertSame('revoked', PublicWorkflow::STATUS_REVOKED);
	}

	public function testNullableRequesterEmailCanBeCleared(): void {
		$workflow = new PublicWorkflow();
		$workflow->setRequesterEmail('x@example.com');
		$workflow->setRequesterEmail(null);
		$this->assertNull($workflow->getRequesterEmail());
	}
}
