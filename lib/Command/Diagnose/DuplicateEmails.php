<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Command\Diagnose;

use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pre-flight safety check for adding a new login provider (e.g. an OIDC
 * provider like Signa/Keycloak) that links to existing Nextcloud accounts by
 * email address.
 *
 * LibreSign resolves a signer's Nextcloud user by uid first, falling back to
 * IUserManager::getByEmail() — see Service\IdentifyMethod\Account::getSigner().
 * That fallback throws if more than one account shares an email. Today that
 * can only happen by coincidence; once a second login door exists that links
 * by email, it becomes something an admin should rule out ahead of time
 * instead of discovering it as a signing-time failure.
 *
 * Run this BEFORE enabling a new email-linked login provider. It does not
 * modify anything — it only reports.
 */
final class DuplicateEmails extends Command {
	public function __construct(
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('libresign:diagnose:duplicate-emails')
			->setDescription('List Nextcloud accounts that share an email address — run before enabling a new login provider that links accounts by email, so any collisions can be resolved by hand first');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$io->title('LibreSign — Duplicate Email Diagnostic');

		/** @var array<string, IUser[]> */
		$byEmail = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$byEmail): void {
			$email = $user->getEMailAddress();
			if ($email === null || $email === '') {
				return;
			}
			$byEmail[strtolower($email)][] = $user;
		});

		$duplicates = array_filter($byEmail, fn (array $users): bool => count($users) > 1);

		if (empty($duplicates)) {
			$io->success('No duplicate emails found. Safe to enable a new email-linked login provider.');
			return Command::SUCCESS;
		}

		$rows = [];
		foreach ($duplicates as $email => $users) {
			foreach ($users as $user) {
				$rows[] = [
					$email,
					$user->getUID(),
					$user->getDisplayName(),
					$user->getBackendClassName(),
				];
			}
		}

		$io->table(['Email', 'UID', 'Display name', 'Backend'], $rows);
		$io->error(sprintf(
			'Found %d email address(es) shared by more than one account. Resolve these before enabling a new login provider that links accounts by email — LibreSign cannot disambiguate a signer when more than one account shares an email (see Service\IdentifyMethod\Account::getSigner()).',
			count($duplicates)
		));

		return Command::FAILURE;
	}
}
