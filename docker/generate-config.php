<?php
// Generates ndxzsite/config/config.php from environment variables.
// Used by the container entrypoint for FORCE_CONFIG_GEN. The full
// installer (ndxzstudio/install-cli.php) also writes this file.

define('SITE', 'Bonjour!');

require_once __DIR__ . '/../ndxzstudio/lib/installer.php';

$config = IndexhibitInstaller::configFromEnv();
$path = __DIR__ . '/../ndxzsite/config/config.php';

if (!IndexhibitInstaller::writeConfigFile($config, $path))
{
	fwrite(STDERR, "Could not write $path\n");
	exit(1);
}

echo "Generated $path\n";
