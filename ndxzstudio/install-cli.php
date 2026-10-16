<?php
/**
* Indexhibit CLI installer.
*
* Usage:
*   php ndxzstudio/install-cli.php
*
* Reads DB_*, SITE_NAME and ADMIN_* environment variables, creates the
* schema and seed data if needed, and writes ndxzsite/config/config.php.
* Safe to run repeatedly.
*/

define('SITE', 'Bonjour!');

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit('Not found');
}

require_once dirname(__DIR__) . '/ndxzsite/config/options.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/helper/time.php';
require_once __DIR__ . '/lib/installer.php';

$config = IndexhibitInstaller::configFromEnv();

try
{
	$installer = new IndexhibitInstaller($config);
	$installer->run();
}
catch (Throwable $e)
{
	fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
	exit(1);
}

echo "Indexhibit is installed.\n";
