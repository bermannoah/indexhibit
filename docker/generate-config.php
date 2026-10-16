<?php
// Generates ndxzsite/config/config.php from environment variables.
// Used by the container entrypoint so DB credentials are never baked
// into the image or committed to git.

$required = array('DB_HOST', 'DB_NAME', 'DB_USER');
foreach ($required as $key)
{
    $value = getenv($key);
    if ($value === false || trim($value) === '')
    {
        fwrite(STDERR, "Missing required environment variable: $key\n");
        exit(1);
    }
}

$config = array(
    'db'   => getenv('DB_NAME'),
    'user' => getenv('DB_USER'),
    'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'host' => getenv('DB_HOST'),
    'sql'  => 'mysql',
);

$prefix = getenv('DB_PREFIX') !== false && getenv('DB_PREFIX') !== '' ? getenv('DB_PREFIX') : 'ndxz_';

$content  = "<?php if (!defined('SITE')) exit('No direct script access allowed');\n\n";
$content .= "\$indx['db']   = " . var_export($config['db'], true) . ";\n";
$content .= "\$indx['user'] = " . var_export($config['user'], true) . ";\n";
$content .= "\$indx['pass'] = " . var_export($config['pass'], true) . ";\n";
$content .= "\$indx['host'] = " . var_export($config['host'], true) . ";\n";
$content .= "\$indx['sql']  = 'mysql';\n\n";
$content .= "if (!defined('PX')) { define('PX', " . var_export($prefix, true) . "); }\n";

$path = __DIR__ . '/../ndxzsite/config/config.php';
if (file_put_contents($path, $content) === false)
{
    fwrite(STDERR, "Could not write $path\n");
    exit(1);
}

echo "Generated $path\n";
