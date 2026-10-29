<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\BackgroundJob;

use OCA\Libresign\BackgroundJob\PeriodReportJob;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PeriodReportJobTest extends TestCase {
	private PeriodReportService&MockObject $service;
	private LoggerInterface&MockObject $logger;
	private PeriodReportJob $job;

	public function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(PeriodReportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new PeriodReportJob($this->createMock(ITimeFactory::class), $this->service, $this->logger);
	}

	public function testRunsEveryFifteenMinutesAndIsTimeSensitive(): void {
		$this->assertSame(900, $this->job->getInterval());
		$this->assertTrue($this->job->isTimeSensitive());
	}

	public function testRunDelegatesToTheService(): void {
		$this->service->expects($this->once())->method('run');

		$this->job->run(null);
	}

	public function testRunSwallowsAndLogsErrorsWithoutTheMessage(): void {
		$this->service->method('run')->willThrowException(new \RuntimeException('mail to someone@example.org failed'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context === ['exception' => \RuntimeException::class]),
		);

		$this->job->run(null);
	}

	public function testJobIsRegisteredInInfoXml(): void {
		$xml = simplexml_load_file(__DIR__ . '/../../../../appinfo/info.xml');
		$this->assertNotFalse($xml);

		$jobs = array_map('strval', iterator_to_array($xml->{'background-jobs'}->job, false));

		$this->assertContains(PeriodReportJob::class, $jobs);
	}
}
