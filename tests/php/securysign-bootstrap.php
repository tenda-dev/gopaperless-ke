<?php

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$loader = require __DIR__ . '/../../vendor/autoload.php';
$loader->setClassMapAuthoritative(false);

// The runtime classmap is dumped with --no-dev, or the nextcloud/ocp stubs
// shadow the server's own OCP and fatal Nextcloud. That leaves the suite short
// of what the server normally provides: the OCP interfaces these tests mock,
// and the psr/* packages libresign keeps out of its own classmap for the same
// reason. Map both here so the tests do not depend on which way the autoloader
// was last dumped.
$loader->addPsr4('OCP\\', __DIR__ . '/../../vendor/nextcloud/ocp/OCP');

// Read each prefix from the package rather than deriving it: psr/http-message
// is Psr\Http\Message, not Psr\HttpMessage, and only its manifest knows that.
foreach (glob(__DIR__ . '/../../vendor/psr/*/composer.json') as $manifest) {
	$psr4 = json_decode((string)file_get_contents($manifest), true)['autoload']['psr-4'] ?? [];
	foreach ($psr4 as $prefix => $path) {
		$loader->addPsr4($prefix, dirname($manifest) . '/' . $path);
	}
}
