<?php if (!defined('SITE')) exit('No direct script access allowed');

// version
define('VERSION', '2.1.6');
	
// Paths/definitions of things (relative to index file)
define('LIBPATH', 'lib');
define('HELPATH', 'helper');
define('MODPATH', 'module');
define('DBPATH', 'db');
define('LANGPATH', 'lang');
define('EXTPATH', 'ext');

// paths to internal parts
define('ASSET', 'asset/');
define('CSS', ASSET . 'css/');
define('JS', ASSET . 'js/');
define('IMG', ASSET . 'img/');
define('TPLPATH', ASSET . 'tpl/');

// improve this later
$adjust = @realpath(dirname(__FILE__));
$adjust = str_replace(DIRECTORY_SEPARATOR, '/', $adjust);
define('BASENAME', '/ndxzstudio');
define('DIRNAME', str_replace(BASENAME, '', $adjust));

// let's make our root path
$self = '';

// Reverse-proxy / CDN support.
// Set TRUSTED_PROXIES to a comma-separated list of proxy IPs, or '*' to
// trust the standard forwarded headers (appropriate when the app is only
// reachable through Fly.io and Bunny.net).
$trusted_proxies = getenv('TRUSTED_PROXIES');
$trusted_proxies = ($trusted_proxies === false) ? '' : trim($trusted_proxies);
$trust_all_proxies = ($trusted_proxies === '*');
$trusted_list = array_filter(array_map('trim', explode(',', $trusted_proxies)));
$remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$is_trusted_proxy = $trust_all_proxies || in_array($remote_addr, $trusted_list, true);

if ($is_trusted_proxy)
{
	if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']))
	{
		$_SERVER['HTTPS'] = (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'on' : 'off';
	}
	if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
	{
		$forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$_SERVER['REMOTE_ADDR'] = trim($forwarded[0]);
	}
	if (isset($_SERVER['HTTP_X_FORWARDED_HOST']))
	{
		$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
	}
}

$protocol   = (empty($_SERVER['HTTPS'])) ? 'http' : 'https';
$servername = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
$serverport = (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != '80') ? ':' . $_SERVER['SERVER_PORT'] : '';

$public_url = getenv('PUBLIC_URL');
if ($public_url !== false && trim($public_url) !== '')
{
	define('BASEURL', rtrim(trim($public_url), '/'));
}
else
{
	$path = dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/');
	$path = str_replace('/ndxzstudio', '', $path);
	$base = $protocol . '://' . preg_replace('/\/+/', '/', $servername . $serverport . $path);
	$base = str_replace(':443', '', $base);
	define('BASEURL', preg_replace("/\/$/i", '', $base)); // no trailing slashes
}

// Public base for uploaded media. Falls back to the site base so local/
// non-CDN installs behave exactly as before.
define('MEDIA_BASE', (defined('MEDIA_URL_SETTING') && MEDIA_URL_SETTING !== '') ? MEDIA_URL_SETTING : BASEURL);

// Add default types for files, images and movies upload
// if we add new 'media' we need to update modedit.js
$default['images'] 	= array('jpg', 'gif', 'png', 'jpeg');
$default['media']	= array('mp4');
$default['sound']	= array('mp3');
$default['files']	= array('txt', 'pdf', 'doc', 'xls', 'eps', 'dwg', 'zip');
$default['link']	= array('url');
$default['flash']   = array();

// EXPERIMENTAL - video basically
$default['services'] = array('youtube', 'vimeo');
$default['video'] = array_merge($default['media'], $default['services']);

// these are all the formats we can display
$medias = array_merge($default['images'], $default['media'], $default['flash'], $default['services']);
$default['medias'] = array_merge($default['images'], $default['media'], $default['flash'], $default['services']);

// kinds of sections
$default['section_types'] = array(0 => 'default', 1 => 'chronological', 3 => 'tags');

// this drives the tabs in system/admin
$default['system_admin'] = array('theme', 'formats', 'plugins', 'spacer', 'settings', 'sections', 'assets', 'tag', 'spacer', 'statistics', 'spacer', 'users');

// files sources
$default['filesource'] = array('exhibit', 'all', 'section', 'subsection', 'tag');

$default['operands'] = array(0 => 'default', 1 => 'permalinked', 2 => 'overlay', 3 => 'unlinked');

$default['parse'] = 'parse_default';

$default['image_library'] = (extension_loaded('imagick')) ? 'media_imagick' : 'media_gd';

// Media storage adapter (local disk and/or Bunny Storage Zone).
require_once dirname(__FILE__) . '/lib/storage.php';