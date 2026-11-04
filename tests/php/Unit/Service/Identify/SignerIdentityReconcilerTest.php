<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Identify;

use OCA\Libresign\Collaboration\Collaborators\EmailAccountPlugin;
use OCA\Libresign\Collaboration\Collaborators\SignerPlugin;
use OCA\Libresign\Service\Identify\AccountDiscoveryPolicy;
use OCA\Libresign\Service\Identify\SignerIdentityReconciler;
use OCP\IUser;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SignerIdentityReconcilerTest extends TestCase {
	private function user(string $uid, string $dn = ''): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($dn !== '' ? $dn : $uid);
		$user->method('isEnabled')->willReturn(true);
		return $user;
	}

	/**
	 * @param array<string, array{email: array{account: ?IUser, discoverable: bool}, uid: array{account: ?IUser, discoverable: bool}}> $map
	 */
	private function reconciler(array $map, ?LoggerInterface $logger = null, array $throwFor = []): SignerIdentityReconciler {
		$policy = $this->createMock(AccountDiscoveryPolicy::class);
		$policy->method('resolve')->willReturnCallback(function (string $email) use ($map, $throwFor): array {
			if (in_array($email, $throwFor, true)) {
				throw new \RuntimeException('resolve failed');
			}
			return $map[$email] ?? [
				'email' => ['account' => null, 'discoverable' => false],
				'uid' => ['account' => null, 'discoverable' => false],
			];
		});
		return new SignerIdentityReconciler($policy, $logger ?? $this->createStub(LoggerInterface::class));
	}

	private function path(?IUser $account, bool $discoverable): array {
		return ['account' => $account, 'discoverable' => $discoverable];
	}

	private function external(string $email): array {
		return ['label' => $email, 'shareWithDisplayNameUnique' => $email, 'method' => 'email', 'value' => ['shareType' => IShare::TYPE_EMAIL, 'shareWith' => $email]];
	}

	private function historical(string $email, string $dn): array {
		return ['label' => $dn, 'shareWithDisplayNameUnique' => $email, 'method' => 'email', 'value' => ['shareType' => SignerPlugin::TYPE_SIGNER, 'shareWith' => $email]];
	}

	private function account54(string $uid, string $dn, string $email): array {
		return ['label' => $dn, 'shareWithDisplayNameUnique' => $email, 'method' => 'account', 'value' => ['shareType' => EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT, 'shareWith' => $uid]];
	}

	public function testDiscoverableExternalUpgradesToAccount(): void {
		$alice = $this->user('a', 'Alice');
		$rec = $this->reconciler(['a@x' => ['email' => $this->path($alice, true), 'uid' => $this->path(null, false)]]);
		$out = $rec->reconcile([$this->external('a@x')], []);
		$this->assertCount(1, $out);
		$this->assertSame('account', $out[0]['method']);
		$this->assertSame('a', $out[0]['value']['shareWith']);
		$this->assertSame(EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT, $out[0]['value']['shareType']);
		$this->assertSame('a@x', $out[0]['shareWithDisplayNameUnique']);
	}

	public function testExistingNonDiscoverableExternalIsSuppressed(): void {
		$erick = $this->user('e', 'Erick');
		$rec = $this->reconciler(['e@x' => ['email' => $this->path($erick, false), 'uid' => $this->path(null, false)]]);
		$out = $rec->reconcile([$this->external('e@x')], []);
		$this->assertSame([], $out);
	}

	public function testHistoricalNonDiscoverableIsPreserved(): void {
		$h = $this->user('h', 'Historic');
		$rec = $this->reconciler(['h@x' => ['email' => $this->path($h, false), 'uid' => $this->path(null, false)]]);
		$out = $rec->reconcile([$this->historical('h@x', 'Historic')], []);
		$this->assertCount(1, $out);
		$this->assertSame(SignerPlugin::TYPE_SIGNER, $out[0]['value']['shareType']);
		$this->assertSame('email', $out[0]['method']);
	}

	public function testGenuineExternalNoAccountKept(): void {
		$rec = $this->reconciler([]);
		$out = $rec->reconcile([$this->external('new@x')], []);
		$this->assertCount(1, $out);
		$this->assertSame(IShare::TYPE_EMAIL, $out[0]['value']['shareType']);
	}

	public function testHistoricalUpgradesAndDedupesAgainstExistingAccount(): void {
		$u1 = $this->user('u1', 'User One');
		$rec = $this->reconciler(['u1@x' => ['email' => $this->path($u1, true), 'uid' => $this->path(null, false)]]);
		$out = $rec->reconcile([$this->account54('u1', 'User One', 'u1@x'), $this->historical('u1@x', 'User One')], []);
		$accounts = array_values(array_filter($out, static fn ($i) => ($i['method'] ?? '') === 'account' && $i['value']['shareWith'] === 'u1'));
		$this->assertCount(1, $accounts);
		$this->assertContains('email', $accounts[0]['_via'] ?? []);
	}

	public function testEmailShapedUidDualAccountsEmitBothEmailFirst(): void {
		$alice = $this->user('a', 'Alice');
		$bob = $this->user('b@x', 'Bob');
		$rec = $this->reconciler(['b@x' => ['email' => $this->path($alice, true), 'uid' => $this->path($bob, true)]]);
		$out = $rec->reconcile([$this->external('b@x')], []);
		$this->assertCount(2, $out);
		$this->assertSame('a', $out[0]['value']['shareWith']);
		$this->assertSame(['email'], $out[0]['_via']);
		$this->assertSame('b@x', $out[1]['value']['shareWith']);
		$this->assertSame(['uid'], $out[1]['_via']);
	}

	public function testBothPathsSameAccountSingleRowMergedVia(): void {
		$same = $this->user('s', 'Same');
		$rec = $this->reconciler(['same@x' => ['email' => $this->path($same, true), 'uid' => $this->path($same, true)]]);
		$out = $rec->reconcile([$this->external('same@x')], []);
		$this->assertCount(1, $out);
		$this->assertContains('email', $out[0]['_via']);
		$this->assertContains('uid', $out[0]['_via']);
	}

	public function testLookupExceptionPreservesHistoricalNoUpgrade(): void {
		$rec = $this->reconciler([], null, ['boom@x']);
		$out = $rec->reconcile([$this->historical('boom@x', 'Boom')], []);
		$this->assertCount(1, $out);
		$this->assertSame(SignerPlugin::TYPE_SIGNER, $out[0]['value']['shareType']);
	}

	public function testLookupExceptionSuppressesExternalFailClosed(): void {
		$rec = $this->reconciler([], null, ['bad@x']);
		$out = $rec->reconcile([$this->external('bad@x')], []);
		$this->assertSame([], $out);
	}

	public function testCandidateIsolationGoodSurvivesWhenSiblingThrows(): void {
		$rec = $this->reconciler([], null, ['bad@x']);
		$out = $rec->reconcile([$this->external('bad@x'), $this->external('good@x')], []);
		$this->assertCount(1, $out);
		$this->assertSame('good@x', $out[0]['value']['shareWith']);
	}

	public function testExceptionLoggedWithoutRawIdentifier(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())
			->method('warning')
			->willReturnCallback(function (string $message, array $context): void {
				$this->assertStringNotContainsString('bad@x', json_encode($context));
				$this->assertArrayHasKey('ref', $context);
			});
		$rec = $this->reconciler([], $logger, ['bad@x']);
		$rec->reconcile([$this->external('bad@x')], []);
	}

	public function testPhoneAndAccountRowsPassThroughUnchanged(): void {
		$rec = $this->reconciler([]);
		$phone = ['label' => '+254', 'shareWithDisplayNameUnique' => '+254', 'method' => 'sms', 'value' => ['shareType' => 51, 'shareWith' => '+254']];
		$account = $this->account54('acc', 'Acc', 'acc@x');
		$out = $rec->reconcile([$phone, $account], []);
		$this->assertCount(2, $out);
		$this->assertSame($phone, $out[0]);
		$this->assertSame($account['value']['shareWith'], $out[1]['value']['shareWith']);
	}

	public function testSnapshotRecoversHistoricalWhenUnifyKeptExternalTwin(): void {
		// The surviving item is the external twin, but the snapshot shows a
		// historical existed for the same (non-discoverable) address.
		$e = $this->user('e', 'Erick');
		$rec = $this->reconciler(['dup@x' => ['email' => $this->path($e, false), 'uid' => $this->path(null, false)]]);
		$historicalItem = $this->historical('dup@x', 'Historic Erick');
		$snapshot = ['dup@x' => ['hadHistorical' => true, 'hadExternal' => true, 'historicalItem' => $historicalItem]];
		$out = $rec->reconcile([$this->external('dup@x')], $snapshot);
		$this->assertCount(1, $out);
		$this->assertSame(SignerPlugin::TYPE_SIGNER, $out[0]['value']['shareType']);
		$this->assertSame('Historic Erick', $out[0]['label']);
	}

	public function testBuildSnapshotCapturesHistoricalAndExternalPerShareWith(): void {
		$rec = $this->reconciler([]);
		$raw = [
			'exact' => [
				'emails' => [$this->external('collide@x')],
			],
			'signer' => [$this->historical('collide@x', 'Hist')],
			'emails' => [$this->external('lonely@x')],
		];
		$snapshot = $rec->buildSnapshot($raw);
		$this->assertTrue($snapshot['collide@x']['hadHistorical']);
		$this->assertTrue($snapshot['collide@x']['hadExternal']);
		$this->assertNotNull($snapshot['collide@x']['historicalItem']);
		$this->assertFalse($snapshot['lonely@x']['hadHistorical']);
		$this->assertTrue($snapshot['lonely@x']['hadExternal']);
	}

	public function testCatastrophicSafeFilterKeepsSafeDropsBareExternal(): void {
		$logger = $this->createStub(LoggerInterface::class);
		$policy = $this->createStub(AccountDiscoveryPolicy::class);
		$rec = new SignerIdentityReconciler($policy, $logger);
		$raw = [
			$this->account54('u1', 'U1', 'u1@x'),
			$this->historical('h@x', 'H'),
			['label' => '+1', 'shareWithDisplayNameUnique' => '+1', 'method' => 'sms', 'value' => ['shareType' => 51, 'shareWith' => '+1']],
			$this->external('masked@x'),
			$this->external('bare@x'),
		];
		$snapshot = ['masked@x' => ['hadHistorical' => true, 'hadExternal' => true, 'historicalItem' => null]];
		$out = $rec->safeFilter($raw, $snapshot);
		$shareWiths = array_map(static fn ($i) => $i['value']['shareWith'], $out);
		$this->assertContains('u1', $shareWiths);
		$this->assertContains('h@x', $shareWiths);
		$this->assertContains('+1', $shareWiths);
		$this->assertContains('masked@x', $shareWiths);
		$this->assertNotContains('bare@x', $shareWiths);
	}
}
