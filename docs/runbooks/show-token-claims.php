<?php

declare(strict_types=1);

/**
 * Prints the claim NAMES of the id_token from each recent user_oidc login, plus
 * the access token's names from the last SecurySign refusal in the log.
 *
 * Names only, never values: the values are the user's identity. Seeing `sub` in
 * the id_token but not in the access token is the finding — see
 * docs/runbooks/securysign-onboarding-gate.md, "signa-rp-15 access tokens carry
 * no sub".
 *
 * Exists as a file rather than a documented one-liner because the equivalent
 * inline PHP is unquotable in PowerShell, which is where it gets run.
 *
 *   docker exec --user www-data <container> \
 *     php /var/www/html/apps-extra/libresign/docs/runbooks/show-token-claims.php
 */

require_once '/var/www/html/lib/base.php';

function claim_names(string $jwt): string {
	$parts = explode('.', $jwt);
	if (count($parts) !== 3) {
		return 'opaque token, ' . count($parts) . ' segment(s)';
	}
	$payload = strtr($parts[1], '-_', '+/');
	$payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
	$decoded = base64_decode($payload, true);
	$claims = $decoded === false ? null : json_decode($decoded, true);
	return is_array($claims) ? implode(' ', array_keys($claims)) : 'unreadable payload';
}

$db = \OC::$server->get(\OCP\IDBConnection::class);
$crypto = \OC::$server->get(\OCP\Security\ICrypto::class);

$q = $db->getQueryBuilder();
$q->select('id', 'user_id', 'provider_id', 'id_token')
	->from('user_oidc_sessions')
	->orderBy('id', 'DESC')
	->setMaxResults(5);

echo 'ID TOKENS (from oc_user_oidc_sessions)', PHP_EOL;
foreach ($q->executeQuery()->fetchAll() as $row) {
	echo PHP_EOL, '  ', $row['user_id'], '  (provider ', $row['provider_id'], ')', PHP_EOL;
	try {
		$names = claim_names($crypto->decrypt((string)$row['id_token']));
	} catch (\Throwable $e) {
		echo '    could not decrypt: ', $e->getMessage(), PHP_EOL;
		continue;
	}
	echo '    claims: ', $names, PHP_EOL;
	echo '    sub:    ', (str_contains(' ' . $names . ' ', ' sub ') ? 'PRESENT' : 'MISSING'), PHP_EOL;
}

echo PHP_EOL, 'ACCESS TOKEN (last SecurySign refusal in nextcloud.log)', PHP_EOL;
$log = '/var/www/html/data/nextcloud.log';
$found = null;
foreach (is_readable($log) ? file($log) : [] as $line) {
	if (str_contains($line, 'tokenClaims')) {
		$found = $line;
	}
}
if ($found === null) {
	echo '  no refusal logged yet — load a LibreSign page while the gate is on', PHP_EOL;
} else {
	preg_match('/"tokenClaims":"([^"]*)"/', $found, $m);
	$names = $m[1] ?? '';
	// The newest refusal may predate a fix. Print when it happened so a stale
	// MISSING is not read as the current state.
	preg_match('/"time":"([^"]*)"/', $found, $t);
	echo '    logged: ', $t[1] ?? 'unknown', '  (a refusal older than your last login is stale)', PHP_EOL;
	echo '    claims: ', $names, PHP_EOL;
	echo '    sub:    ', (str_contains(' ' . $names . ' ', ' sub ') ? 'PRESENT' : 'MISSING'), PHP_EOL;
}
