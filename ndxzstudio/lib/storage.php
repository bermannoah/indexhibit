<?php if (!defined('SITE')) exit('No direct script access allowed');

/**
* Storage adapter for uploaded media.
*
* Files are always written to local disk first (GD and friends need a real
* path), then mirrored to a Bunny Storage Zone when MEDIA_DRIVER=bunny.
* Deletes are mirrored too. With the default driver this is a no-op.
*
* The storage key mirrors the app-relative path:
*   DIRNAME . '/files/gimgs/pic.jpg'  ->  files/gimgs/pic.jpg
* which corresponds to MEDIA_URL . '/files/gimgs/pic.jpg'.
*
* @version 1.0
*/
#[AllowDynamicProperties]
class Storage
{
	/**
	* Is a remote storage backend configured?
	*
	* @return bool
	*/
	public static function enabled()
	{
		return (defined('MEDIA_DRIVER') && MEDIA_DRIVER === 'bunny'
			&& defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE !== ''
			&& defined('BUNNY_STORAGE_KEY') && BUNNY_STORAGE_KEY !== '');
	}

	/**
	* Map a local path to a storage key.
	*
	* @param string $localPath
	* @return string
	*/
	public static function remoteKey($localPath)
	{
		$local = preg_replace('#/+#', '/', str_replace('\\', '/', (string) $localPath));
		$base  = preg_replace('#/+#', '/', str_replace('\\', '/', DIRNAME));

		if (strpos($local, $base . '/') === 0)
		{
			$local = substr($local, strlen($base) + 1);
		}
		elseif ($local === $base)
		{
			$local = '';
		}

		return ltrim($local, '/');
	}

	/**
	* Upload a local file to storage.
	*
	* @param string $localPath
	* @param string $remoteKey optional explicit key
	* @return bool
	*/
	public static function put($localPath, $remoteKey = null)
	{
		if (!self::enabled()) return true;
		if (!is_file($localPath)) return false;

		if ($remoteKey === null) $remoteKey = self::remoteKey($localPath);
		$remoteKey = ltrim((string) $remoteKey, '/');
		if ($remoteKey === '') return false;

		return self::request('PUT', $remoteKey, $localPath);
	}

	/**
	* Delete a file from storage. Accepts a local path or a relative key.
	*
	* @param string $remoteKey
	* @return bool
	*/
	public static function delete($remoteKey)
	{
		if (!self::enabled()) return true;

		if (strpos((string) $remoteKey, DIRNAME) === 0)
		{
			$remoteKey = self::remoteKey($remoteKey);
		}

		$remoteKey = ltrim((string) $remoteKey, '/');
		if ($remoteKey === '') return false;

		$ok = self::request('DELETE', $remoteKey);

		// Drop the deleted file from the CDN edge as well.
		if ($ok && function_exists('ndxz_bunny_purge_url') && defined('MEDIA_BASE'))
		{
			ndxz_bunny_purge_url(MEDIA_BASE . '/' . $remoteKey);
		}

		return $ok;
	}

	/**
	* Perform the storage API request.
	*
	* @param string $method
	* @param string $remoteKey
	* @param string $localPath
	* @return bool
	*/
	protected static function request($method, $remoteKey, $localPath = null)
	{
		$url = BUNNY_STORAGE_ENDPOINT . '/' . BUNNY_STORAGE_ZONE . '/' . self::encodeKey($remoteKey);

		$ch = curl_init($url);

		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('AccessKey: ' . BUNNY_STORAGE_KEY),
			CURLOPT_TIMEOUT => 120,
			CURLOPT_CONNECTTIMEOUT => 10,
		));

		$fh = null;

		if ($method === 'PUT')
		{
			$fh = fopen($localPath, 'rb');
			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $fh);
			curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localPath));
		}
		else
		{
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}

		curl_exec($ch);

		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($fh) fclose($fh);

		$ok = ($code >= 200 && $code < 300) || ($method === 'DELETE' && $code === 404);

		if (!$ok)
		{
			error_log('Storage::' . strtolower($method) . ' failed (' . $code . '): ' . $remoteKey . ' ' . $err);
		}

		return $ok;
	}

	/**
	* Encode each path segment, keeping the slashes.
	*
	* @param string $key
	* @return string
	*/
	protected static function encodeKey($key)
	{
		$parts = explode('/', (string) $key);
		$parts = array_map('rawurlencode', $parts);
		return implode('/', $parts);
	}
}
