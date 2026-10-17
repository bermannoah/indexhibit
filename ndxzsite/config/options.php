<?php if (!defined('SITE')) exit('No direct script access allowed');

// mod_rewrite is being used?
// in case the server does not have mod_rewrite
define('MODREWRITE', false);

// image quality
$default['img_quality'] = 100;
$default['systhumb'] = 150;

// images max size kilobytes
// be careful with shared hosting
$default['maxsize'] = 500;

// things you don't want stats to track
$default['ignore_ip'] = array();

// language default in case of error
define('LANGUAGE', 'en-us');

// for paths to files/images
// NOTE: BASEFILES/GIMGS are both the on-disk paths (DIRNAME . GIMGS) and the
// trailing URL paths (MEDIA_BASE . GIMGS). Uploaded media is served from
// MEDIA_URL when configured; dimgs and the page cache stay on the app origin.
define('BASEFILES', '/files');
define('GIMGS', BASEFILES . '/gimgs');

// ---- media storage --------------------------------------------------
// MEDIA_URL is the public base for uploaded media (CDN hostname). When empty,
// media URLs fall back to BASEURL exactly as before.
$ndxz_media_url = getenv('MEDIA_URL');
define('MEDIA_URL_SETTING', ($ndxz_media_url !== false && trim($ndxz_media_url) !== '') ? rtrim(trim($ndxz_media_url), '/') : '');

// 'local' (default) writes only to the app disk; 'bunny' also pushes to a
// Bunny Storage Zone via BUNNY_STORAGE_*.
$ndxz_media_driver = getenv('MEDIA_DRIVER');
define('MEDIA_DRIVER', ($ndxz_media_driver !== false && trim($ndxz_media_driver) !== '') ? trim($ndxz_media_driver) : 'local');

$ndxz_bunny_zone = getenv('BUNNY_STORAGE_ZONE');
define('BUNNY_STORAGE_ZONE', ($ndxz_bunny_zone !== false) ? trim($ndxz_bunny_zone) : '');

$ndxz_bunny_key = getenv('BUNNY_STORAGE_KEY');
define('BUNNY_STORAGE_KEY', ($ndxz_bunny_key !== false) ? trim($ndxz_bunny_key) : '');

$ndxz_bunny_endpoint = getenv('BUNNY_STORAGE_ENDPOINT');
define('BUNNY_STORAGE_ENDPOINT', ($ndxz_bunny_endpoint !== false && trim($ndxz_bunny_endpoint) !== '') ? rtrim(trim($ndxz_bunny_endpoint), '/') : 'https://storage.bunnycdn.com');

// use an editor, i guess...
$default['tinymce'] = false; // not yet

$default['parsing'] = false;
	
// cache time
$default['caching'] = false;
$default['cache_time'] = 15; // minutes

// first year
$default['first_year'] = 2004;
	
// define the default encoding
$default['encoding'] = 'UTF-8';

// basic sizes for images and thumbnails uploading
$default['thumbsize'] = array(200 => 200, 250 => 250, 300 => 300, 350 => 350);
$default['imagesize'] = array(700 => 700, 800 => 800, 900 => 900, 'full' => 9999);

// max exhibit images upload
$default['exhibit_imgs'] = 6;

// subsections within urls /more/more/more...
// not sure if this is 100% yet
$default['subdir'] = true;

$default['timer'] = false;

// media players defaults
$default['screencolor'] = 'f3f3f3';
$default['autoplay'] = false;

// default module
$default['module'] = 'exhibits';