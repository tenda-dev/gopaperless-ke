<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;

/**
 * Renders and sends the period report email.
 *
 * One message per recipient, with exactly one To address and no Cc/Bcc, so
 * recipients never see each other. Every dynamic string placed in the HTML
 * body is escaped; the plain-text alternative is supplied separately.
 */
class PeriodReportMailer {
	public const TEMPLATE_ID = 'libresign.OperationsPeriodReport';

	public function __construct(
		private IMailer $mailer,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private OperationsAccessService $access,
	) {
	}

	/**
	 * @param array<string, mixed> $report output of PeriodReportBuilder::build()
	 * @throws \Throwable when the message could not be built or sent. The
	 *                    transport may report a rejected recipient by return value
	 *                    instead of throwing, so a non-empty result is raised
	 *                    here; otherwise the caller would count it as delivered.
	 */
	public function send(array $report, string $email, string $name, bool $test = false): void {
		$template = $this->buildTemplate($report, $test);
		$message = $this->mailer->createMessage();
		$message->setTo([$email => $name]);
		$message->useTemplate($template);
		$failed = $this->mailer->send($message);
		if ($failed !== []) {
			// Deliberately no recipient or transport detail in the message.
			throw new \RuntimeException('The mail transport rejected the message');
		}
	}

	public function isValidAddress(string $email): bool {
		return $this->mailer->validateMailAddress($email);
	}

	/**
	 * @param array<string, mixed> $report
	 * @param bool $test marks the subject as a test message
	 */
	public function buildTemplate(array $report, bool $test = false): IEMailTemplate {
		$frequency = PeriodFrequency::from((string)$report['frequency']);
		$period = PeriodReportFormatter::periodLabel((string)$report['period']['from'], (string)$report['period']['to']);
		$previousPeriod = PeriodReportFormatter::periodLabel((string)$report['previousPeriod']['from'], (string)$report['previousPeriod']['to']);
		$timezone = (string)$report['timezone'];
		$unit = $this->unitLabel($frequency);
		/** @var array<string, mixed> $current */
		$current = $report['current'];
		/** @var array<string, mixed> $previous */
		$previous = $report['previous'];

		$template = $this->mailer->createEMailTemplate(self::TEMPLATE_ID);
		$template->setSubject($test
			? $this->l10n->t('[Test] GoPaperless operations report: %s', [$period])
			: $this->l10n->t('GoPaperless operations report: %s', [$period]));
		$template->addHeader();
		$template->addHeading($this->heading($frequency));

		$this->text($template, $this->l10n->t('Period: %1$s (%2$s). Compared with %3$s.', [$period, $timezone, $previousPeriod]));

		// Revenue: one line per currency; currencies are never summed.
		$revenue = PeriodReportFormatter::revenueComparison($current['revenue'] ?? null, $previous['revenue'] ?? null);
		if ($revenue === null) {
			$this->line($template, $this->l10n->t('Revenue'), $this->l10n->t('not available'), '');
		} elseif ($revenue === []) {
			// Both periods have no confirmed payments in any currency.
			$this->line($template, $this->l10n->t('Revenue'), $this->l10n->t('no confirmed payments in this period'), $this->deltaText(PeriodReportFormatter::delta(0, ($previous['revenue'] ?? null) === null ? null : 0), $unit, false, ''));
		} else {
			foreach ($revenue as $row) {
				$this->line(
					$template,
					$this->l10n->t('Revenue (%s)', [$row['currency']]),
					PeriodReportFormatter::money($row['current'], $row['currency']),
					$this->deltaText($row['delta'], $unit, true, $row['currency']),
				);
			}
		}

		$this->countLine($template, $this->l10n->t('Signatures completed'), $current['signatures'] ?? null, $previous['signatures'] ?? null, $unit);
		$this->countLine($template, $this->l10n->t('Documents completed'), $current['documents'] ?? null, $previous['documents'] ?? null, $unit);
		$this->countLine($template, $this->l10n->t('Requester sponsorships created'), $current['sponsorships'] ?? null, $previous['sponsorships'] ?? null, $unit);
		// A point-in-time total, so there is no previous-period comparison.
		$registered = $current['registeredUsers'] ?? null;
		$this->line(
			$template,
			$this->l10n->t('Total registered users'),
			is_int($registered) ? (string)$registered : $this->l10n->t('not available'),
			'',
		);

		$this->text($template, $this->l10n->t('Revenue is the sum of confirmed paid payments in the period, shown per currency. Sponsorships are records created in the period, not completed signings. Total registered users is the number of accounts when this report was generated.'));

		$link = $this->dashboardLink();
		if ($link !== null) {
			$template->addBodyButton($this->l10n->t('Open Operations dashboard'), $link);
		}

		return $template;
	}

	private function heading(PeriodFrequency $frequency): string {
		return match ($frequency) {
			PeriodFrequency::DAILY => $this->l10n->t('Daily operations report'),
			PeriodFrequency::WEEKLY => $this->l10n->t('Weekly operations report'),
			PeriodFrequency::MONTHLY => $this->l10n->t('Monthly operations report'),
		};
	}

	private function unitLabel(PeriodFrequency $frequency): string {
		return match ($frequency) {
			PeriodFrequency::DAILY => $this->l10n->t('previous day'),
			PeriodFrequency::WEEKLY => $this->l10n->t('previous week'),
			PeriodFrequency::MONTHLY => $this->l10n->t('previous month'),
		};
	}

	private function dashboardLink(): ?string {
		if (!$this->access->isEnabled()) {
			return null;
		}
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute('libresign.page.indexFPath', ['path' => 'operations']);
		} catch (\Throwable) {
			return null;
		}
		return $url !== '' ? $url : null;
	}

	private function countLine(IEMailTemplate $template, string $label, mixed $current, mixed $previous, string $unit): void {
		if (!is_int($current)) {
			$this->line($template, $label, $this->l10n->t('not available'), '');
			return;
		}
		$delta = PeriodReportFormatter::delta($current, is_int($previous) ? $previous : null);
		$this->line($template, $label, (string)$current, $this->deltaText($delta, $unit, false, ''));
	}

	/**
	 * @param array{kind: string, absolute: int, percent: int|null} $delta
	 */
	private function deltaText(array $delta, string $unit, bool $money, string $currency): string {
		$amount = $money
			? PeriodReportFormatter::money(abs($delta['absolute']), $currency)
			: (string)abs($delta['absolute']);
		$sign = $delta['absolute'] < 0 ? '-' : '+';
		$change = match ($delta['kind']) {
			PeriodReportFormatter::DELTA_NO_CHANGE => $this->l10n->t('no change'),
			PeriodReportFormatter::DELTA_UP_FROM_ZERO => $this->l10n->t('up from 0'),
			PeriodReportFormatter::DELTA_CHANGE => $sign . $amount . ' (' . ($delta['percent'] >= 0 ? '+' : '') . $delta['percent'] . '%)',
			default => $this->l10n->t('not available'),
		};
		return $this->l10n->t('vs %1$s: %2$s', [$unit, $change]);
	}

	private function line(IEMailTemplate $template, string $label, string $value, string $comparison): void {
		$htmlComparison = $comparison === '' ? '' : ' <span style="color:#6b7280">— ' . $this->escape($comparison) . '</span>';
		$template->addBodyText(
			'<strong>' . $this->escape($label) . ':</strong> ' . $this->escape($value) . $htmlComparison,
			$label . ': ' . $value . ($comparison === '' ? '' : ' — ' . $comparison),
		);
	}

	private function text(IEMailTemplate $template, string $text): void {
		$template->addBodyText($this->escape($text), $text);
	}

	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES);
	}
}
