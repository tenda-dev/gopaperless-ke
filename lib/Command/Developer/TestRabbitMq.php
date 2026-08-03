<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Command\Developer;

use OCA\Libresign\Service\Messaging\RabbitMqService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestRabbitMq extends Command {
	public function __construct(
		private RabbitMqService $rabbitMqService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('libresign:rabbit:test')
			->addArgument(
				'paymentId',
				InputArgument::REQUIRED
			);
	}

	protected function execute(
		InputInterface $input,
		OutputInterface $output,
	): int {

		$paymentId = (int)$input->getArgument('paymentId');

		$this->rabbitMqService
			->publishPaymentVerification($paymentId);

		$output->writeln(
			sprintf(
				'Published payment %d',
				$paymentId
			)
		);

		return Command::SUCCESS;
	}
}
