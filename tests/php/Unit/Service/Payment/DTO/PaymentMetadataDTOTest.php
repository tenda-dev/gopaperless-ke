<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment\DTO;

use OCA\Libresign\Service\Payment\DTO\AutoChargeDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\SelectedMnoDTO;
use OCA\Libresign\Service\Payment\DTO\SelectionDTO;
use OCA\Libresign\Service\Payment\DTO\SuggestedMnoDTO;
use OCA\Libresign\Tests\Unit\TestCase;

/**
 * Guards the sponsorship-workflow card-payment pointer stored in
 * PaymentMetadataDTO.context (context.sponsorshipWorkflow.fileUuid). The
 * pointer must survive JSON round-tripping and every unrelated `.with()`
 * mutation the payment lifecycle performs, because it is read back in the
 * authoritative payment-success/verify path long after initiation. Updating it
 * must not discard unrelated top-level context or sibling sponsorshipWorkflow
 * fields.
 */
final class PaymentMetadataDTOTest extends TestCase {
	private function make(array $context = []): PaymentMetadataDTO {
		return new PaymentMetadataDTO(
			updatedAt: null,
			preferredProvider: 'dpo',
			executedProvider: 'dpo',
			flow: 'redirect',
			method: 'card',
			redirectUrl: 'https://app.example.com/f/payment/return',
			selected: SelectedMnoDTO::fromArray([]),
			suggested: SuggestedMnoDTO::fromArray([]),
			selection: SelectionDTO::fromArray([]),
			autoCharge: AutoChargeDTO::fromArray([]),
			confidence: null,
			alreadyCharged: false,
			instructions: null,
			providerExecutionState: null,
			providerPayload: ProviderPayloadDTO::fromArray([]),
			context: $context,
			providerError: null,
			returnUrl: 'https://app.example.com/f/request',
		);
	}

	public function testWorkflowUuidDefaultsToNull(): void {
		self::assertNull($this->make()->getSponsorshipWorkflowFileUuid());
	}

	public function testWithWorkflowUuidStoresUnderNestedContext(): void {
		$dto = $this->make()->withSponsorshipWorkflowFileUuid('file-uuid-123');
		self::assertSame('file-uuid-123', $dto->getSponsorshipWorkflowFileUuid());
		self::assertSame(
			['sponsorshipWorkflow' => ['fileUuid' => 'file-uuid-123']],
			$dto->context,
		);
	}

	public function testWithWorkflowUuidPreservesUnrelatedTopLevelContext(): void {
		$dto = $this->make(['carrier' => 'safaricom'])
			->withSponsorshipWorkflowFileUuid('file-uuid-123');
		self::assertSame('safaricom', $dto->context['carrier']);
		self::assertSame('file-uuid-123', $dto->getSponsorshipWorkflowFileUuid());
	}

	public function testWithWorkflowUuidPreservesSiblingWorkflowFields(): void {
		// A sibling key inside context.sponsorshipWorkflow must survive an update.
		$dto = $this->make(['sponsorshipWorkflow' => ['intent' => 'resume', 'fileUuid' => 'old']])
			->withSponsorshipWorkflowFileUuid('file-uuid-new');
		self::assertSame('file-uuid-new', $dto->getSponsorshipWorkflowFileUuid());
		self::assertSame('resume', $dto->context['sponsorshipWorkflow']['intent']);
	}

	public function testUpdatingWorkflowUuidKeepsUnrelatedContext(): void {
		$dto = $this->make(['carrier' => 'safaricom', 'sponsorshipWorkflow' => ['intent' => 'resume', 'fileUuid' => 'old']])
			->withSponsorshipWorkflowFileUuid('file-uuid-new');
		self::assertSame('safaricom', $dto->context['carrier']);
		self::assertSame('resume', $dto->context['sponsorshipWorkflow']['intent']);
		self::assertSame('file-uuid-new', $dto->getSponsorshipWorkflowFileUuid());
	}

	public function testWorkflowUuidSurvivesToArrayFromArrayRoundTrip(): void {
		$dto = $this->make()->withSponsorshipWorkflowFileUuid('file-uuid-123');
		$restored = PaymentMetadataDTO::fromArray($dto->toArray());
		self::assertSame('file-uuid-123', $restored->getSponsorshipWorkflowFileUuid());
	}

	public function testWorkflowUuidSurvivesUnrelatedWithMutations(): void {
		$dto = $this->make()
			->withSponsorshipWorkflowFileUuid('file-uuid-123')
			->with(flow: 'mobile_direct')
			->with(executedProvider: 'daraja')
			->with(alreadyCharged: true);
		self::assertSame('file-uuid-123', $dto->getSponsorshipWorkflowFileUuid());
		self::assertSame('mobile_direct', $dto->flow);
		self::assertTrue($dto->alreadyCharged);
	}

	public function testWithoutWorkflowUuidRemovesPointerAndKeepsSiblings(): void {
		$dto = $this->make(['carrier' => 'safaricom', 'sponsorshipWorkflow' => ['intent' => 'resume', 'fileUuid' => 'A']])
			->withoutSponsorshipWorkflowFileUuid();
		self::assertNull($dto->getSponsorshipWorkflowFileUuid());
		self::assertSame('resume', $dto->context['sponsorshipWorkflow']['intent']);
		self::assertSame('safaricom', $dto->context['carrier']);
	}

	public function testWithoutWorkflowUuidDropsEmptyNestedObject(): void {
		$dto = $this->make(['sponsorshipWorkflow' => ['fileUuid' => 'A']])
			->withoutSponsorshipWorkflowFileUuid();
		self::assertNull($dto->getSponsorshipWorkflowFileUuid());
		self::assertArrayNotHasKey('sponsorshipWorkflow', $dto->context);
	}

	public function testWithoutWorkflowUuidIsSafeWhenAbsent(): void {
		$dto = $this->make(['carrier' => 'safaricom'])->withoutSponsorshipWorkflowFileUuid();
		self::assertNull($dto->getSponsorshipWorkflowFileUuid());
		self::assertSame('safaricom', $dto->context['carrier']);
	}

	public function testWithoutWorkflowUuidSurvivesRoundTrip(): void {
		$dto = $this->make(['carrier' => 'safaricom', 'sponsorshipWorkflow' => ['intent' => 'resume', 'fileUuid' => 'A']])
			->withoutSponsorshipWorkflowFileUuid();
		$restored = PaymentMetadataDTO::fromArray($dto->toArray());
		self::assertNull($restored->getSponsorshipWorkflowFileUuid());
		self::assertSame('resume', $restored->context['sponsorshipWorkflow']['intent']);
	}

	public function testMalformedWorkflowContextReturnsNull(): void {
		self::assertNull($this->make(['sponsorshipWorkflow' => 'not-an-array'])->getSponsorshipWorkflowFileUuid());
		self::assertNull($this->make(['sponsorshipWorkflow' => ['fileUuid' => '']])->getSponsorshipWorkflowFileUuid());
		self::assertNull($this->make(['sponsorshipWorkflow' => ['fileUuid' => 123]])->getSponsorshipWorkflowFileUuid());
		self::assertNull($this->make(['sponsorshipWorkflow' => []])->getSponsorshipWorkflowFileUuid());
	}
}
