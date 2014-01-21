<?php
/**
 * @author Tomáš Blatný
 */

require_once "exceptions.php";

spl_autoload_register(function ($type) {
	static $paths = array(
		'greeny\maillibrary\connection' => 'Connection.php',
		'greeny\maillibrary\mailbox' => 'Mailbox.php',
		'greeny\maillibrary\selection' => 'Selection.php',
		'greeny\maillibrary\mail' => 'Mail.php',
		'greeny\maillibrary\drivers\idriver' => 'Drivers/IDriver.php',
		'greeny\maillibrary\drivers\imapdriver' => 'Drivers/ImapDriver.php',
	);

	$type = ltrim(strtolower($type), '\\'); // PHP namespace bug #49143

	if (isset($paths[$type])) {
		require_once __DIR__ . '/' . $paths[$type];
	}
});