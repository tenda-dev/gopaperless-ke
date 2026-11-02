<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportMailer;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

final class PeriodReportMailerTest extends TestCase {
	private IMailer&MockObject $mailer;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private OperationsAccessService&MockObject $access;
	private IEMailTemplate&MockObject $template;
	private PeriodReportMailer $reportMailer;
	/** @var list<array{string, string|bool}> */
	private array $bodyTexts = [];
	/** @var list<string> */
	private array $headings = [];
	/** @var list<array{string, string}> */
	private array $buttons = [];
	private ?string $subject = null;

	public function setUp(): void {
		parent::setUp();
		$this->bodyTexts = [];
		$this->headings = [];
		$this->buttons = [];
		$this->subject = null;

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, $parameters));
		$this->mailer = $this->createMock(IMailer::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://gopaperless.example/apps/libresign/f/operations');
		$this->access = $this->createMock(OperationsAccessService::class);
		$this->access->method('isEnabled')->willReturn(true);

		$this->template = $this->createMock(IEMailTemplate::class);
		$this->template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->subject = $subject;
		});
		$this->template->method('addHeading')->willReturnCallback(function (string $title): void {
			$this->headings[] = $title;
		});
		$this->template->method('addBodyText')->willReturnCallback(function (string $text, $plain = ''): void {
			$this->bodyTexts[] = [$text, $plain];
		});
		$this->template->method('addBodyButton')->willReturnCallback(function (string $text, string $url): void {
			$this->buttons[] = [$text, $url];
		});
		$this->mailer->method('createEMailTemplate')->with(PeriodReportMailer::TEMPLATE_ID)->willReturn($this->template);

		$this->reportMailer = new PeriodReportMailer($this->mailer, $this->l10n, $this->urlGenerator, $this->access);
	}

	private function report(string $frequency = 'weekly', array $overrides = []): array {
		return array_replace_recursive([
			'frequency' => $frequency,
			'timezone' => 'Africa/Nairobi',
			'period' => ['from' => '2026-10-19', 'to' => '2026-10-25'],
			'previousPeriod' => ['from' => '2026-10-12', 'to' => '2026-10-18'],
			'current' => ['revenue' => ['KES' => 1000000, 'USD' => 5000], 'signatures' => 12, 'documents' => 5, 'sponsorships' => 7, 'registeredUsers' => 42],
			'previous' => ['revenue' => ['KES' => 800000], 'signatures' => 10, 'documents' => 0, 'sponsorships' => 7, 'registeredUsers' => 42],
		], $overrides);
	}

	private function allText(): string {
		return implode("\n", array_map(static fn (array $pair): string => $pair[0] . ' || ' . $pair[1], $this->bodyTexts));
	}

	public function testSendsOneMessagePerRecipientWithExactlyOneToAndNoCcOrBcc(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setTo')->with(['ada@example.org' => 'Ada'])->willReturnSelf();
		$message->expects($this->once())->method('useTemplate')->with($this->template)->willReturnSelf();
		$message->expects($this->never())->method('setCc');
		$message->expects($this->never())->method('setBcc');
		$this->mailer->expects($this->once())->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message);

		$this->reportMailer->send($this->report(), 'ada@example.org', 'Ada');
	}

	public function testRecipientReportedAsFailedByTheTransportIsRaisedNotSwallowed(): void {
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn(['ada@example.org']);

		try {
			$this->reportMailer->send($this->report(), 'ada@example.org', 'Ada');
			$this->fail('A rejected recipient must not count as delivered');
		} catch (\RuntimeException $e) {
			$this->assertStringNotContainsString('ada@example.org', $e->getMessage());
		}
	}

	public function testBodyNeverContainsTheRecipientAddress(): void {
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);

		$this->reportMailer->send($this->report(), 'ada@example.org', 'Ada Lovelace');

		$this->assertStringNotContainsString('ada@example.org', $this->allText() . $this->subject);
		$this->assertStringNotContainsString('Ada Lovelace', $this->allText() . $this->subject);
	}

	public function testContentCoversTheAgreedFiguresWithComparisons(): void {
		$this->reportMailer->buildTemplate($this->report());
		$text = $this->allText();

		$this->assertSame('GoPaperless operations report: 2026-10-19 – 2026-10-25', $this->subject);
		$this->assertSame(['Weekly operations report'], $this->headings);
		$this->assertStringContainsString('2026-10-19 – 2026-10-25 (Africa/Nairobi)', $text);
		$this->assertStringContainsString('2026-10-12 – 2026-10-18', $text);
		$this->assertStringContainsString('Revenue (KES):', $text);
		$this->assertStringContainsString('KES 10,000.00', $text);
		$this->assertStringContainsString('vs previous week: +KES 2,000.00 (+25%)', $text);
		// USD is absent in the previous period: compared against 0, never summed with KES.
		$this->assertStringContainsString('Revenue (USD):', $text);
		$this->assertStringContainsString('USD 50.00', $text);
		$this->assertStringContainsString('up from 0', $text);
		$this->assertStringContainsString('Signatures completed:', $text);
		$this->assertStringContainsString('vs previous week: +2 (+20%)', $text);
		$this->assertStringContainsString('Documents completed:', $text);
		$this->assertStringContainsString('Requester sponsorships created:', $text);
		$this->assertStringContainsString('no change', $text);
		$this->assertStringContainsString('records created in the period, not completed signings', $text);
	}

	public function testTotalRegisteredUsersIsShownWithoutAComparison(): void {
		$this->reportMailer->buildTemplate($this->report());
		$text = $this->allText();

		$this->assertStringContainsString('Total registered users: 42', $text);
		$this->assertStringNotContainsString('New accounts', $text);
		$this->assertStringNotContainsString('Total registered users: 42 — ', $text);
	}

	public function testUnavailableRegisteredUsersIsNotShownAsZero(): void {
		$this->reportMailer->buildTemplate($this->report('weekly', ['current' => ['registeredUsers' => null]]));

		$this->assertStringContainsString('Total registered users: not available', $this->allText());
		$this->assertStringNotContainsString('Total registered users: 0', $this->allText());
	}

	public static function frequencies(): array {
		return [
			'daily' => ['daily', 'Daily operations report', 'previous day'],
			'weekly' => ['weekly', 'Weekly operations report', 'previous week'],
			'monthly' => ['monthly', 'Monthly operations report', 'previous month'],
		];
	}

	#[DataProvider('frequencies')]
	public function testHeadingAndComparisonLabelFollowTheFrequency(string $frequency, string $heading, string $label): void {
		$this->reportMailer->buildTemplate($this->report($frequency));

		$this->assertSame([$heading], $this->headings);
		$this->assertStringContainsString('vs ' . $label . ':', $this->allText());
	}

	public function testHostileStringsAreEscapedInHtmlAndLeftIntactInPlainText(): void {
		$hostile = '<img src=x onerror=alert(1)>';
		$report = $this->report('weekly', ['current' => ['revenue' => [$hostile => 100]], 'previous' => ['revenue' => []]]);
		// array_replace_recursive keeps KES/USD from the base; replace revenue wholesale.
		$report['current']['revenue'] = [$hostile => 100];
		$report['previous']['revenue'] = [];
		$report['timezone'] = '"><script>x</script>';

		$this->reportMailer->buildTemplate($report);

		foreach ($this->bodyTexts as [$html, $plain]) {
			$this->assertStringNotContainsString('<img', $html);
			$this->assertStringNotContainsString('<script', $html);
		}
		$this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $this->allText());
		$this->assertStringContainsString($hostile, implode("\n", array_column($this->bodyTexts, 1)));
	}

	public function testDashboardButtonOnlyWhenTheDashboardIsEnabled(): void {
		$this->reportMailer->buildTemplate($this->report());
		$this->assertSame([['Open Operations dashboard', 'https://gopaperless.example/apps/libresign/f/operations']], $this->buttons);

		$access = $this->createMock(OperationsAccessService::class);
		$access->method('isEnabled')->willReturn(false);
		$this->buttons = [];
		(new PeriodReportMailer($this->mailer, $this->l10n, $this->urlGenerator, $access))->buildTemplate($this->report());
		$this->assertSame([], $this->buttons);
	}

	public function testNoButtonWhenTheLinkCannotBeGenerated(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willThrowException(new \RuntimeException('no route'));

		(new PeriodReportMailer($this->mailer, $this->l10n, $urlGenerator, $this->access))->buildTemplate($this->report());

		$this->assertSame([], $this->buttons);
	}

	public function testEveryBodyTextProvidesAnExplicitPlainTextAlternative(): void {
		$this->reportMailer->buildTemplate($this->report());

		foreach ($this->bodyTexts as [$html, $plain]) {
			$this->assertIsString($plain);
			$this->assertNotSame('', $plain);
		}
	}

	public function testUnavailableRevenueIsNotShownAsZero(): void {
		$report = $this->report();
		$report['current']['revenue'] = null;
		$this->reportMailer->buildTemplate($report);

		$this->assertStringContainsString('Revenue: not available', $this->allText());
	}

	public function testTestMessagesAreMarkedInTheSubject(): void {
		$this->reportMailer->buildTemplate($this->report(), true);
		$this->assertStringStartsWith('[Test] ', (string)$this->subject);

		$this->reportMailer->buildTemplate($this->report());
		$this->assertStringStartsWith('GoPaperless operations report', (string)$this->subject);
	}
}
