<?php

namespace cjrasmussen\WebRequest;

use Exception;
use RuntimeException;

class WebRequest
{
	/**
	 * Gets an external file's contents using cURL
	 *
	 * 15-20 times faster than file_get_contents()
	 *
	 * @param string $url
	 * @param bool $ignore_ssl_errors
	 * @return string|bool
	 */
	public static function getFileContents(string $url, bool $ignore_ssl_errors = false)
	{
		$c = curl_init();
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_URL, $url);
		curl_setopt($c, CURLOPT_MAXREDIRS, 5);
		curl_setopt($c, CURLOPT_HEADER, 1);
		curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 10);

		if ($ignore_ssl_errors) {
			curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
		}

		$curl_error = curl_errno($c);
		if ($curl_error) {
			$msg = 'CURL Errno: ' . $curl_error . PHP_EOL;
			$msg .= 'CURL URL: ' . $url . PHP_EOL;
			$msg .= 'Timestamp: ' . date('Y-m-d g:i:s A') . PHP_EOL;
			throw new RuntimeException(trim($msg));
		}

		$response = curl_exec($c);
		$header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
		curl_close($c);

		$header = trim(substr($response, 0, $header_size));
		$body = trim(substr($response, $header_size));
		$headers = explode("\r\n", $header);

		if (preg_match('|HTTP(.*)200(.*)|is', $headers[0])) {
			return trim($body);
		}

		$location = '';
		foreach ($headers AS $h) {
			if (strpos($h, 'Location: ') === 0) {
				$location = substr($h, 10);
				break;
			}
		}

		if ($location) {
			if (strpos($location, '/') === 0) {
				$url_parts = parse_url($url);
				$new_url = $url_parts['scheme'] . '://' . $url_parts['host'] . $location;
				return self::getFileContents($new_url, $ignore_ssl_errors);
			}

			return self::getFileContents($location, $ignore_ssl_errors);
		}

		return false;
	}

	/**
	 * Gets the response header for a given request using cURL
	 *
	 * @param string $url
	 * @param bool $ignore_ssl_errors
	 * @return array
	 */
	public static function getResponseHeader(string $url, bool $ignore_ssl_errors = false): array
	{
		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, $url);
		curl_setopt($c, CURLOPT_HEADER, true);
		curl_setopt($c, CURLOPT_NOBODY, true);
		curl_setopt($c, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);

		if ($ignore_ssl_errors) {
			curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
		}

		curl_exec($c);
		$header = curl_getinfo($c);
		curl_close($c);

		return $header;
	}

	/**
	 * Gets the URL a given URL redirects to, based on header information
	 *
	 * @param string $url
	 * @return string
	 */
	public static function getUrlRedirect(string $url): string
	{
		$header = self::getResponseHeader($url);
		return $header['redirect_url'];
	}

	/**
	 * Open a socket to effectively make an async post to a page
	 *
	 * @param string $url
	 * @param array $params
	 */
	public static function postAsync(string $url, array $params): void
	{
		// SET UP PARAMETERS
		$post_string = '';
		foreach ($params AS $key => &$val) {
			if (is_array($val)) {
				$val = implode(',', $val);
			}
			$post_string .= $key . '=' . urlencode($val) . '&';
		}
		unset($val);

		if ('' !== $post_string) {
			$post_string = substr($post_string, 0, -1);
		}

		// OPEN THE SOCKET
		$url_parts = parse_url($url);

		$port = 80;
		if ($url_parts['port']) {
			$port = $url_parts['port'];
		} elseif ($url_parts['schema'] === 'https') {
			$port = 443;
		}

		$fp = fsockopen($url_parts['host'], $port, $errno, $errstr, 60);

		if ($fp) {
			// SEND THE DATA
			$out = 'POST ' . $url_parts['path'] . ' HTTP/1.1' . "\r\n";
			$out .= 'Host: ' . $url_parts['host'] . "\r\n";
			$out .= 'Content-Type: application/x-www-form-urlencoded' . "\r\n";
			$out .= 'Content-Length: ' . strlen($post_string) . "\r\n";
			$out .= 'Connection: Close' . "\r\n\r\n";
			if ($post_string) {
				$out .= $post_string;
			}

			fwrite($fp, $out);
		}

		// CLOSE THE SOCKET
		fclose($fp);
	}

	/**
	 * Gets an external file's contents using cURL, first requesting a cookie from another page
	 *
	 * @param $url
	 * @param string|null $cookie_source
	 * @param array $headers
	 * @param bool $ignore_ssl_errors
	 * @return string
	 * @throws Exception
	 * @see https://stackoverflow.com/questions/895786/how-to-get-the-cookies-from-a-php-curl-into-a-variable
	 */
	public static function getFileContentsWithCookie($url, ?string $cookie_source = null, array $headers = [], bool $ignore_ssl_errors = false): string
	{
		// DO A REQUEST TO GET A SESSION COOKIE
		$c = curl_init($cookie_source);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_HEADER, 1);

		if ($ignore_ssl_errors) {
			curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
		}

		$result = curl_exec($c);

		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
		$cookies = $cookie = [];
		foreach ($matches[1] as $item) {
			parse_str($item, $cookie);
			$cookies[] = $cookie;
		}
		$cookies = array_merge(...$cookies);

		sleep(random_int(3, 8));

		// GET THE NEW DATA
		$headers[] = 'Cookie: ' . http_build_query($cookies);
		$headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.84 Safari/537.36';
		$headers[] = 'Accept: application/json, text/javascript, */*; q=0.01';
		$headers[] = 'Accept-Language: en-US,en;q=0.9';

		$c = curl_init();
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_URL, $url);
		curl_setopt($c, CURLOPT_REFERER, $cookie_source);
		curl_setopt($c, CURLOPT_MAXREDIRS, 5);
		curl_setopt($c, CURLOPT_HEADER, 0);
		curl_setopt($c, CURLOPT_HTTPHEADER, $headers);

		if ($ignore_ssl_errors) {
			curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
		}

		$html = curl_exec($c);
		curl_close($c);

		return $html;
	}

	/**
	 * Gets an external file's contents and mime type
	 *
	 * @param string $url
	 * @param bool $ignore_ssl_errors
	 * @return object{contents: string, mimeType: string}
	 */
	public static function getFileContentsAndMimeType(string $url, bool $ignore_ssl_errors = false): object
	{
		$contents = self::getFileContents($url, $ignore_ssl_errors);

		$temp_file_path = tempnam(('/' . trim(sys_get_temp_dir(), '/') . '/'), 'o6tmp');
		file_put_contents($temp_file_path, $contents);

		exec("file -bi '" . $temp_file_path . "'", $output);
		[$mime_type,] = explode(';', $output[0]);

		unlink($temp_file_path);

		return (object)[
			'contents' => $contents,
			'mimeType' => $mime_type,
		];
	}
}
