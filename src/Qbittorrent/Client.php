<?php

namespace Blackout\Qbittorrent;

use Blackout\Qbittorrent\DTO\Torrent;
use Blackout\Qbittorrent\DTO\Torrent\Properties;
use Blackout\Qbittorrent\DTO\Torrent\File;
use Blackout\Qbittorrent\DTO\Torrent\Tracker;
use Blackout\Qbittorrent\DTO\Torrent\WebSeed;
use Blackout\Qbittorrent\DTO\Torrent\Peer;
use Blackout\Qbittorrent\DTO\Torrent\Piece;
use Blackout\Qbittorrent\DTO\Category;
use Blackout\Qbittorrent\DTO\Preferences;
use Blackout\Qbittorrent\DTO\BuildInfo;
use Blackout\Qbittorrent\DTO\Cookie;
use Blackout\Qbittorrent\DTO\Transfer;
use Blackout\Qbittorrent\DTO\MainData;
use Blackout\Qbittorrent\DTO\Rss\Rule;
use Blackout\Qbittorrent\DTO\Rss\Feed;
use Blackout\Qbittorrent\DTO\Log\Message;
use Blackout\Qbittorrent\DTO\Log\Peer as LogPeer;
use Blackout\Qbittorrent\DTO\Search\Result as SearchResult;
use Blackout\Qbittorrent\DTO\Search\Status;
use Blackout\Qbittorrent\DTO\Search\Plugin as SearchPlugin;
use Blackout\Qbittorrent\Enum\SearchStatus;

use Blackout\Helper;
use Blackout\Qbittorrent\Helper as QbtHelper;
use GuzzleHttp\Client as Http;
use GuzzleHttp\Exception\RequestException;

mb_internal_encoding('UTF-8');

class Client
{
	private string $baseUri;
	private string $username;
	private string $password;

	private Http $http;
	private string $authCookie = '';
	private string $authCookieCache = __DIR__ . '/../../cache/cookies.json';

	public function __construct(string $baseUri, string $username, string $password)
	{
		$this->baseUri = $baseUri;
		$this->username = $username;
		$this->password = $password;

		$this->http = new Http(
		[
		'base_uri' => rtrim($this->baseUri, '/') . '/',
		'timeout'  => 10,
		'verify'   => true,
		]);

		if (file_exists($this->authCookieCache))
		{
			$cache = Helper::jsonDecode(file_get_contents($this->authCookieCache));
			if ($cache['expires'] > time())
			{
				$this->authCookie = $cache['cookie'];
			}
		}
	}

	public function login(string $username, string $password): self
	{
		try
		{
			$response = $this->http->post('api/v2/auth/login',
			[
				'form_params' =>
				[
					'username' => $username,
					'password' => $password
				],
				'headers' => [
					'Referer' => rtrim($this->baseUri, '/'),
				],
			]);

			$body = trim((string)$response->getBody());
			$code = $response->getStatusCode();

			if ($code != 204)
			#if ($body != '')
			{
				throw new \Exception('Authentication failed: ' . $body . ' (' . $code . ')');
			}
			
			$this->authCookie = $response->getHeaderLine('Set-Cookie');

			@mkdir(dirname($this->authCookieCache), recursive: true);

			file_put_contents($this->authCookieCache, Helper::jsonEncode([
				'cookie' => $this->authCookie,
				'expires' => time() + 3600,
			]));

			return $this;
		}
		catch (RequestException $e)
		{
			throw new \Exception($e->getMessage());
		}
	}

	private function checkAuthenticated(): void
	{
		if (!$this->authCookie)
		{
			$this->login($this->username, $this->password);
		}
	}

	private function requestRaw(string $method, string $uri, array $options = [], bool $retryOnAuthFailure = true): string
	{
		$this->checkAuthenticated();

		$options['headers']['Cookie'] = $this->authCookie;

		try
		{
			$response = $this->http->request($method, $uri, $options);

			return (string) $response->getBody();
		}
		catch (RequestException $e)
		{
			// stale/expired session cookie — re-login once and retry
			if ($retryOnAuthFailure && $e->getResponse()?->getStatusCode() === 403)
			{
				$this->authCookie = '';

				return $this->requestRaw($method, $uri, $options, false);
			}

			throw new \Exception($e->getMessage());
		}
	}

	private function request(string $method, string $uri, array $options = []): array
	{
		$body = $this->requestRaw($method, $uri, $options);

		if (empty($body)) return [];

		try {
			return QbtHelper::normalizeArrayUtf8(Helper::jsonDecode($body));
		} catch (\Throwable) {
			return [];
		}
	}

	public function getTorrentsByCategory(string $category, array $query = []): array
	{
		$query['category'] = $category;

		return $this->getTorrents($query);
	}

	public function getTorrentsByHash(string|array $hashes, array $query = []): array
	{
		$query['hashes'] = implode('|', (array)$hashes);

		return $this->getTorrents($query);
	}


	public function getTorrentFiles(string $hash): array
	{
		return $this->requestDto('GET', 'api/v2/torrents/files', File::class,
		[
			'query' => ['hash' => $hash]
		]);
	}


	public function getRssRules(array $query = []): array
	{
		return $this->hydrateRssRules($this->request('GET', '/api/v2/rss/rules'));
	}

	private function hydrateRssRules(array $rules): array
	{
		$result = [];

		foreach ($rules as $name => $rule)
		{
			$result[$name] = new Rule(['name' => $name] + $rule, $this);
		}

		return $result;
	}

	# generated
	# app
	public function getVersion(): string
	{
		return $this->requestRaw('GET', '/api/v2/app/version');
	}

	public function getWebApiVersion(): string
	{
		return $this->requestRaw('GET', '/api/v2/app/webapiVersion');
	}

	public function getBuildInfo(): BuildInfo
	{
		return $this->requestDto('GET', '/api/v2/app/buildInfo', BuildInfo::class);
	}

	public function shutdown()
	{
		return $this->request('POST', '/api/v2/app/shutdown');
	}

	public function logout()
	{
		$result = $this->request('POST', '/api/v2/auth/logout');
		$this->authCookie = '';
		@unlink($this->authCookieCache);

		return $result;
	}

	public function getDefaultSavePath(): string
	{
		return $this->requestRaw('GET', '/api/v2/app/defaultSavePath');
	}

	/** @return Cookie[] */
	public function getCookies(): array
	{
		return $this->requestDto('GET', '/api/v2/app/cookies', Cookie::class);
	}

	/** Empty the qBittorrent cookie store. */
	public function clearCookies()
	{
		return $this->setCookies([], merge: false);
	}

	/**
	 * The setCookies endpoint replaces the entire store, so by default we merge:
	 * fetch existing cookies, overwrite any with the same name/domain/path, and
	 * keep the rest. Pass $merge = false to replace the store outright.
	 *
	 * @param array<Cookie|array> $cookies
	 */
	public function setCookies(array $cookies, bool $merge = true)
	{
		$cookies = array_map(
			fn ($c) => $c instanceof Cookie ? $c : new Cookie($c),
			$cookies,
		);

		$key = fn (Cookie $c) => $c->name . "\0" . $c->domain . "\0" . $c->path;

		$byKey = [];

		if ($merge)
		{
			foreach ($this->getCookies() as $existing)
			{
				$byKey[$key($existing)] = $existing;
			}
		}

		foreach ($cookies as $cookie)
		{
			$byKey[$key($cookie)] = $cookie;
		}

		return $this->request('POST', '/api/v2/app/setCookies', [
		'form_params' => [
			'cookies' => json_encode(array_values($byKey)),
		],
		]);
	}

	/**
	 * Import cookies from a Cookie-Editor style JSON export (a JSON array of
	 * {name, value, domain, path, expirationDate, ...} objects) and push them
	 * to qBittorrent via setCookies.
	 *
	 * @return Cookie[] the imported cookies
	 */
	public function importCookiesJson(string $json, string|array $domains = []): array
	{
		$domains = array_map(fn ($d) => ltrim($d, '.'), (array) $domains);

		$cookies = [];

		foreach (Helper::jsonDecode($json) as $row)
		{
			if ($domains !== [] && !$this->cookieDomainMatches($row['domain'] ?? '', $domains))
			{
				continue;
			}

			$cookies[] = new Cookie($row);
		}

		if ($cookies !== [])
		{
			$this->setCookies($cookies);
		}

		return $cookies;
	}

	/**
	 * Import cookies from a raw "name=value;name=value" Cookie header string.
	 * The header carries no domain, so one must be supplied.
	 *
	 * @return Cookie[] the imported cookies
	 */
	public function importCookieHeader(string $header, string $domain, string $path = '/'): array
	{
		$cookies = [];

		foreach (explode(';', $header) as $pair)
		{
			$pair = trim($pair);

			if ($pair === '' || !str_contains($pair, '='))
			{
				continue;
			}

			[$name, $value] = explode('=', $pair, 2);

			$cookies[] = new Cookie([
				'name'   => trim($name),
				'value'  => $value,
				'domain' => $domain,
				'path'   => $path,
			]);
		}

		if ($cookies !== [])
		{
			$this->setCookies($cookies);
		}

		return $cookies;
	}

	/**
	 * Import cookies from a Netscape/curl cookie file (as exported by
	 * Cookie-Editor, wget, curl). Lines are tab-separated:
	 * domain, includeSubdomains, path, secure, expiry, name, value.
	 * The "#HttpOnly_" prefix on the domain is honoured; other comments skipped.
	 *
	 * @return Cookie[] the imported cookies
	 */
	public function importCurlCookies(string $contents, string|array $domains = []): array
	{
		$domains = array_map(fn ($d) => ltrim($d, '.'), (array) $domains);

		$cookies = [];

		foreach (explode("\n", $contents) as $line)
		{
			$line = rtrim($line, "\r");

			if (str_starts_with($line, '#HttpOnly_'))
			{
				$line = substr($line, strlen('#HttpOnly_'));
			}
			elseif ($line === '' || $line[0] === '#')
			{
				continue;
			}

			$fields = explode("\t", $line);

			if (count($fields) < 7)
			{
				continue;
			}

			[$domain, , $path, , $expiry, $name, $value] = $fields;

			if ($domains !== [] && !$this->cookieDomainMatches($domain, $domains))
			{
				continue;
			}

			$cookies[] = new Cookie([
				'name'           => $name,
				'value'          => $value,
				'domain'         => $domain,
				'path'           => $path,
				'expirationDate' => (int) $expiry,
			]);
		}

		if ($cookies !== [])
		{
			$this->setCookies($cookies);
		}

		return $cookies;
	}

	/** @param array<string> $domains bare domains (no leading dot) */
	private function cookieDomainMatches(string $domain, array $domains): bool
	{
		$domain = ltrim($domain, '.');

		foreach ($domains as $d)
		{
			if ($domain === $d || str_ends_with($domain, '.' . $d) || str_contains($domain, $d))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Import cookies from a Chrome or Firefox cookie SQLite file (browser
	 * must be closed — it locks the file) for the given domain(s), and push
	 * them to qBittorrent via setCookies.
	 *
	 * Chrome stores cookie values encrypted; only already-decrypted (plain)
	 * values are imported. Firefox values are plaintext.
	 *
	 * @param string|array<string> $domains
	 * @return Cookie[] the imported cookies
	 */
	public function importBrowserCookies(string $file, string|array $domains): array
	{
		$domains = (array) $domains;

		$db = new \PDO('sqlite:' . $file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

		$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")
			->fetchAll(\PDO::FETCH_COLUMN);

		if (in_array('moz_cookies', $tables, true))
		{
			// Firefox: expiry is seconds since epoch
			$sql = 'SELECT host AS domain, name, value, path, expiry AS expirationDate FROM moz_cookies';
		}
		elseif (in_array('cookies', $tables, true))
		{
			// Chrome: expires_utc is microseconds since 1601-01-01,
			// value is empty when encrypted_value holds the real (encrypted) data
			$chrome = true;
			$sql = "SELECT host_key AS domain, name, value, encrypted_value, path,
				(expires_utc / 1000000 - 11644473600) AS expirationDate
				FROM cookies";
		}
		else
		{
			throw new \Exception("Unrecognized cookie database: {$file}");
		}

		$where = implode(' OR ', array_fill(0, count($domains), 'domain LIKE ?'));
		$stmt  = $db->prepare("{$sql} WHERE {$where}");
		$stmt->execute(array_map(fn ($d) => '%' . ltrim($d, '.') . '%', $domains));

		$key = isset($chrome) ? $this->chromeCookieKey() : null;

		$cookies = [];

		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			if (isset($chrome) && $row['value'] === '' && $row['encrypted_value'] !== '')
			{
				$row['value'] = $this->decryptChromeCookie($row['encrypted_value'], $key);
			}

			unset($row['encrypted_value']);

			$cookies[] = new Cookie($row);
		}

		if ($cookies !== [])
		{
			$this->setCookies($cookies);
		}

		return $cookies;
	}

	/** Derive the Chrome (Linux) cookie AES key from the keyring password. */
	private function chromeCookieKey(): string
	{
		// Chrome's key store password: keyring via secret-tool, else the "peanuts" default
		$password = @shell_exec(
			'secret-tool lookup application chrome 2>/dev/null'
		) ?: 'peanuts';

		return hash_pbkdf2('sha1', trim($password), 'saltysalt', 1, 16, true);
	}

	/** Decrypt a Chrome v10/v11 (Linux) encrypted_value blob. */
	private function decryptChromeCookie(string $encrypted, string $key): string
	{
		if (!str_starts_with($encrypted, 'v10') && !str_starts_with($encrypted, 'v11'))
		{
			return ''; // unknown scheme (e.g. Windows DPAPI) — not handled
		}

		$plain = openssl_decrypt(
			substr($encrypted, 3),
			'aes-128-cbc',
			$key,
			OPENSSL_RAW_DATA,
			str_repeat(' ', 16),
		);

		if ($plain === false)
		{
			return '';
		}

		// Chrome 130+ prepends a 32-byte SHA256 of the host; strip it if present
		return strlen($plain) > 32 && !ctype_print(substr($plain, 0, 32))
			? substr($plain, 32)
			: $plain;
	}

	public function getPreferences(): Preferences
	{
		return $this->requestDto('GET', '/api/v2/app/preferences', Preferences::class);
	}

	public function setPreferences(array $prefs)
	{
		return $this->request('POST', '/api/v2/app/setPreferences', [
		'form_params' => [
			'json' => json_encode($prefs),
		],
		]);
	}

	public function deleteApiKey()
	{
		return $this->request('POST', '/api/v2/app/deleteAPIKey');
	}

	public function rotateApiKey(): string
	{
		$result = $this->request('POST', '/api/v2/app/rotateAPIKey');

		return $result['apiKey'] ?? '';
	}

	public function sendTestEmail()
	{
		return $this->request('POST', '/api/v2/app/sendTestEmail');
	}

	public function getProcessInfo(): array
	{
		return $this->request('GET', '/api/v2/app/processInfo');
	}

	public function getNetworkInterfaces(): array
	{
		return $this->request('GET', '/api/v2/app/networkInterfaceList');
	}

	public function getNetworkInterfaceAddresses(string $iface = ''): array
	{
		return $this->request('GET', '/api/v2/app/networkInterfaceAddressList', [
		'query' => ['iface' => $iface],
		]);
	}

	public function getDirectoryContent(string $dirPath, string $mode = 'all', bool $withMetadata = false): array
	{
		return $this->request('GET', '/api/v2/app/getDirectoryContent', [
		'query' => [
			'dirPath' => $dirPath,
			'mode' => $mode,
			'withMetadata' => $withMetadata ? 'true' : 'false',
		],
		]);
	}

	public function getFreeSpaceAtPath(string $path): int
	{
		return (int)$this->requestRaw('GET', '/api/v2/app/getFreeSpaceAtPath', [
		'query' => [
			'path' => $path,
		],
		]);
	}

	# clientdata
	public function loadClientData(array $keys = []): array
	{
		return $this->request('GET', '/api/v2/clientdata/load', [
		'query' => $keys === [] ? [] : ['keys' => json_encode($keys)],
		]);
	}

	public function storeClientData(array $data)
	{
		return $this->request('POST', '/api/v2/clientdata/store', [
		'form_params' => [
			'data' => json_encode($data),
		],
		]);
	}

	# log
	public function getLog(array $query = []): array
	{
		return $this->requestDto('GET', '/api/v2/log/main', Message::class, [
		'query' => $query,
		]);
	}

	public function getPeerLog(array $query = []): array
	{
		return $this->requestDto('GET', '/api/v2/log/peers', LogPeer::class, [
		'query' => $query,
		]);
	}

	# sync
	public function syncMainData(array $query = []): MainData
	{
		return $this->requestDto('GET', '/api/v2/sync/maindata', MainData::class, [
		'query' => $query,
		]);
	}

	public function syncTorrentPeers(array $query = [])
	{
		return $this->request('GET', '/api/v2/sync/torrentPeers', [
		'query' => $query,
		]);
	}

	# transfer
	public function getTransferInfo(): Transfer
	{
		return $this->requestDto('GET', '/api/v2/transfer/info', Transfer::class);
	}

	public function getSpeedLimitsMode(): bool
	{
		return $this->requestRaw('GET', '/api/v2/transfer/speedLimitsMode') === '1';
	}

	public function toggleSpeedLimitsMode()
	{
		return $this->request('POST', '/api/v2/transfer/toggleSpeedLimitsMode');
	}

	public function setSpeedLimitsMode(bool $alternative)
	{
		return $this->request('POST', '/api/v2/transfer/setSpeedLimitsMode', [
		'form_params' => [
			'mode' => $alternative ? 1 : 0,
		],
		]);
	}

	public function getSpeedLimits(): array
	{
		return $this->request('GET', '/api/v2/transfer/getSpeedLimits');
	}

	public function setSpeedLimits(int $upLimit, int $downLimit, int $altUpLimit, int $altDownLimit)
	{
		return $this->request('POST', '/api/v2/transfer/setSpeedLimits', [
		'form_params' => [
			'up_limit' => $upLimit,
			'dl_limit' => $downLimit,
			'alt_up_limit' => $altUpLimit,
			'alt_dl_limit' => $altDownLimit,
		],
		]);
	}

	public function getGlobalDownloadLimit(): int
	{
		return (int)$this->requestRaw('GET', '/api/v2/transfer/downloadLimit');
	}

	public function setGlobalDownloadLimit(int $limit)
	{
		return $this->request('POST', '/api/v2/transfer/setDownloadLimit', [
		'form_params' => [
			'limit' => $limit,
		],
		]);
	}

	public function getGlobalUploadLimit(): int
	{
		return (int)$this->requestRaw('GET', '/api/v2/transfer/uploadLimit');
	}

	public function setGlobalUploadLimit(int $limit)
	{
		return $this->request('POST', '/api/v2/transfer/setUploadLimit', [
		'form_params' => [
			'limit' => $limit,
		],
		]);
	}

	public function banPeers(string|array $peers)
	{
		return $this->request('POST', '/api/v2/transfer/banPeers', [
		'form_params' => [
			'peers' => implode('|', (array)$peers),
		],
		]);
	}

	# torrents
	public function getTorrents(array $query = []): array
	{
		return $this->requestDto('GET', '/api/v2/torrents/info', Torrent::class, [
		'query' => $query,
		]);
	}

	public function getTorrentProperties(string $hash): Properties
	{
		return $this->requestDto('GET', '/api/v2/torrents/properties', Properties::class, [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function getTorrentTrackers(string $hash)
	{
		return $this->requestDto('GET', '/api/v2/torrents/trackers', Tracker::class, [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function getTorrentPeers(string $hash)
	{
		$data = $this->request('GET', '/api/v2/sync/torrentPeers', [
		'query' => [
			'hash' => $hash,
		],
		]);

		return $this->toDto(Peer::class, array_values($data['peers'] ?? []));
	}

	public function getTorrentPiecesStates(string $hash)
	{
		$states = $this->request('GET', '/api/v2/torrents/pieceStates', [
		'query' => [
			'hash' => $hash,
		],
		]);

		$pairs = array_map(
		fn ($state, $index) => ['index' => $index, 'state' => $state],
		$states,
		array_keys($states),
		);

		return $this->toDto(Piece::class, $pairs);
	}

	public function getTorrentWebSeeds(string $hash): array
	{
		return $this->requestDto('GET', '/api/v2/torrents/webseeds', WebSeed::class, [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function getTorrentPieceHashes(string $hash): array
	{
		return $this->request('GET', '/api/v2/torrents/pieceHashes', [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function getTorrentPieceAvailability(string $hash): array
	{
		return $this->request('GET', '/api/v2/torrents/pieceAvailability', [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function addWebSeeds(string $hash, string|array $urls)
	{
		return $this->request('POST', '/api/v2/torrents/addWebSeeds', [
		'form_params' => [
			'hash' => $hash,
			'urls' => implode('|', (array)$urls),
		],
		]);
	}

	public function editWebSeed(string $hash, string $origUrl, string $newUrl)
	{
		return $this->request('POST', '/api/v2/torrents/editWebSeed', [
		'form_params' => [
			'hash' => $hash,
			'origUrl' => $origUrl,
			'newUrl' => $newUrl,
		],
		]);
	}

	public function removeWebSeeds(string $hash, string|array $urls)
	{
		return $this->request('POST', '/api/v2/torrents/removeWebSeeds', [
		'form_params' => [
			'hash' => $hash,
			'urls' => implode('|', (array)$urls),
		],
		]);
	}

	public function getTorrentsCount(): int
	{
		return (int)$this->requestRaw('GET', '/api/v2/torrents/count');
	}

	public function exportTorrent(string $hash): string
	{
		return $this->requestRaw('GET', '/api/v2/torrents/export', [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function downloadFile(string $hash, string|File $file): string
	{
		return $this->requestRaw('GET', '/api/v2/torrents/downloadFile', [
		'query' => [
			'hash' => $hash,
			'file' => $file instanceof File ? $file->index : $file,
		],
		]);
	}

	public function fetchMetadata(string $source, string $downloader = ''): array
	{
		return $this->request('POST', '/api/v2/torrents/fetchMetadata', [
		'form_params' => [
			'source' => $source,
			'downloader' => $downloader,
		],
		]);
	}

	public function parseMetadata(string $filePath): array
	{
		return $this->request('POST', '/api/v2/torrents/parseMetadata', [
		'multipart' => [
			[
				'name' => 'torrents',
				'contents' => fopen($filePath, 'r'),
				'filename' => str_ends_with(strtolower($filePath), '.torrent')
					? basename($filePath)
					: basename($filePath) . '.torrent',
			],
		],
		]);
	}

	public function saveMetadata(string $source): string
	{
		return $this->requestRaw('GET', '/api/v2/torrents/saveMetadata', [
		'query' => [
			'source' => $source,
		],
		]);
	}

	public function setTorrentComment(string|array $hashes, string $comment)
	{
		return $this->request('POST', '/api/v2/torrents/setComment', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'comment' => $comment,
		],
		]);
	}

	public function setTorrentDownloadPath(string|array $ids, string $path)
	{
		return $this->request('POST', '/api/v2/torrents/setDownloadPath', [
		'form_params' => [
			'id' => implode('|', (array)$ids),
			'path' => $path,
		],
		]);
	}

	public function setTorrentSavePath(string|array $ids, string $path)
	{
		return $this->request('POST', '/api/v2/torrents/setSavePath', [
		'form_params' => [
			'id' => implode('|', (array)$ids),
			'path' => $path,
		],
		]);
	}

	public function setTorrentTags(string|array $hashes, string|array $tags)
	{
		return $this->request('POST', '/api/v2/torrents/setTags', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'tags' => implode(',', (array)$tags),
		],
		]);
	}

	public function getTorrentSslParameters(string $hash): array
	{
		return $this->request('GET', '/api/v2/torrents/SSLParameters', [
		'query' => [
			'hash' => $hash,
		],
		]);
	}

	public function setTorrentSslParameters(string $hash, string $certificate, string $privateKey, string $dhParams)
	{
		return $this->request('POST', '/api/v2/torrents/setSSLParameters', [
		'form_params' => [
			'hash' => $hash,
			'ssl_certificate' => $certificate,
			'ssl_private_key' => $privateKey,
			'ssl_dh_params' => $dhParams,
		],
		]);
	}

	public function stop(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/stop', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function start(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/start', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function reannounce(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/reannounce', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function delete(string|array $hashes, bool $deleteFiles = false)
	{
		return $this->request('POST', '/api/v2/torrents/delete', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'deleteFiles' => $deleteFiles ? 'true' : 'false',
		],
		]);
	}

	public function recheck(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/recheck', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	# trackers / peers
	public function addTrackers(string $hash, string|array $urls)
	{
		return $this->request('POST', '/api/v2/torrents/addTrackers', [
		'form_params' => [
			'hash' => $hash,
			'urls' => implode("\n", (array)$urls),
		],
		]);
	}

	public function editTracker(string $hash, string $origUrl, string $newUrl)
	{
		return $this->request('POST', '/api/v2/torrents/editTracker', [
		'form_params' => [
			'hash' => $hash,
			'origUrl' => $origUrl,
			'newUrl' => $newUrl,
		],
		]);
	}

	public function removeTrackers(string $hash, string|array $urls)
	{
		return $this->request('POST', '/api/v2/torrents/removeTrackers', [
		'form_params' => [
			'hash' => $hash,
			'urls' => implode('|', (array)$urls),
		],
		]);
	}

	public function addPeers(string|array $hashes, string|array $peers)
	{
		return $this->request('POST', '/api/v2/torrents/addPeers', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'peers' => implode('|', (array)$peers),
		],
		]);
	}

	# priority
	public function increasePrio(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/increasePrio', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function decreasePrio(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/decreasePrio', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function topPrio(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/topPrio', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function bottomPrio(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/bottomPrio', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function setFilePrio(string $hash, string|array $ids, int $priority)
	{
		return $this->request('POST', '/api/v2/torrents/filePrio', [
		'form_params' => [
			'hash' => $hash,
			'id' => implode('|', (array)$ids),
			'priority' => $priority,
		],
		]);
	}

	# limits / share limits
	public function getDownloadLimit(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/downloadLimit', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function setDownloadLimit(string|array $hashes, int $limit)
	{
		return $this->request('POST', '/api/v2/torrents/setDownloadLimit', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'limit' => $limit,
		],
		]);
	}

	public function getUploadLimit(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/uploadLimit', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function setUploadLimit(string|array $hashes, int $limit)
	{
		return $this->request('POST', '/api/v2/torrents/setUploadLimit', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'limit' => $limit,
		],
		]);
	}

	public function setShareLimits(string|array $hashes, float $ratioLimit, int $seedingTimeLimit, int $inactiveSeedingTimeLimit, string $shareLimitAction = 'Default', string $shareLimitsMode = 'Default')
	{
		return $this->request('POST', '/api/v2/torrents/setShareLimits', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'ratioLimit' => $ratioLimit,
			'seedingTimeLimit' => $seedingTimeLimit,
			'inactiveSeedingTimeLimit' => $inactiveSeedingTimeLimit,
			'shareLimitAction' => $shareLimitAction,
			'shareLimitsMode' => $shareLimitsMode,
		],
		]);
	}

	# location / naming
	public function setLocation(string|array $hashes, string $location)
	{
		return $this->request('POST', '/api/v2/torrents/setLocation', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'location' => $location,
		],
		]);
	}

	public function renameTorrent(string $hash, string $name)
	{
		return $this->request('POST', '/api/v2/torrents/rename', [
		'form_params' => [
			'hash' => $hash,
			'name' => $name,
		],
		]);
	}

	public function renameFile(string $hash, string $oldPath, string $newPath)
	{
		return $this->request('POST', '/api/v2/torrents/renameFile', [
		'form_params' => [
			'hash' => $hash,
			'oldPath' => $oldPath,
			'newPath' => $newPath,
		],
		]);
	}

	public function renameFolder(string $hash, string $oldPath, string $newPath)
	{
		return $this->request('POST', '/api/v2/torrents/renameFolder', [
		'form_params' => [
			'hash' => $hash,
			'oldPath' => $oldPath,
			'newPath' => $newPath,
		],
		]);
	}

	# flags
	public function setAutoManagement(string|array $hashes, bool $enable)
	{
		return $this->request('POST', '/api/v2/torrents/setAutoManagement', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'enable' => $enable ? 'true' : 'false',
		],
		]);
	}

	public function toggleSequentialDownload(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/toggleSequentialDownload', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function toggleFirstLastPiecePrio(string|array $hashes)
	{
		return $this->request('POST', '/api/v2/torrents/toggleFirstLastPiecePrio', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
		],
		]);
	}

	public function setForceStart(string|array $hashes, bool $value)
	{
		return $this->request('POST', '/api/v2/torrents/setForceStart', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'value' => $value ? 'true' : 'false',
		],
		]);
	}

	public function setSuperSeeding(string|array $hashes, bool $value)
	{
		return $this->request('POST', '/api/v2/torrents/setSuperSeeding', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'value' => $value ? 'true' : 'false',
		],
		]);
	}

	# add torrents
	public function addTorrentUrls(string|array $urls, array $options = []): string
	{
		# /torrents/add returns plain text ("Ok." / "Fails."), not JSON
		$body = trim($this->requestRaw('POST', '/api/v2/torrents/add', [
		'form_params' => array_merge([
			'urls' => implode("\n", (array)$urls),
		], $options),
		]));

		if (!$this->isAddTorrentSuccess($body))
		{
			throw new \Exception('Add torrent failed: ' . $this->describeAddTorrentFailure($body));
		}

		return $body;
	}

	public function addTorrentFile(string $filePath, array $options = []): string
	{
		# /torrents/add returns plain text ("Ok." / "Fails."), not JSON
		$body = trim($this->requestRaw('POST', '/api/v2/torrents/add', [
		'multipart' => array_merge([
			[
				'name' => 'torrents',
				'contents' => fopen($filePath, 'r'),
				'filename' => str_ends_with(strtolower($filePath), '.torrent')
					? basename($filePath)
					: basename($filePath) . '.torrent',
			],
		], $this->buildMultipartOptions($options)),
		]));

		if (!$this->isAddTorrentSuccess($body))
		{
			throw new \Exception('Add torrent failed: ' . $this->describeAddTorrentFailure($body));
		}

		return $body;
	}

	# /torrents/add normally returns "Ok."; some proxies/trackers return a JSON success payload instead
	private function isAddTorrentSuccess(string $body): bool
	{
		if ($body === 'Ok.')
		{
			return true;
		}

		$json = json_decode($body, true);
		return is_array($json) && (($json['success_count'] ?? 0) > 0 || ($json['failure_count'] ?? 1) === 0);
	}

	private function describeAddTorrentFailure(string $body): string
	{
		if ($body === '')
		{
			return '(empty response)';
		}

		$json = json_decode($body, true);
		if (is_array($json))
		{
			$reason = $json['error'] ?? $json['message'] ?? $json['reason'] ?? null;
			if ($reason !== null)
			{
				return (string) $reason;
			}

			$counts = array_intersect_key($json, array_flip(['failure_count', 'pending_count', 'success_count']));
			if ($counts !== [])
			{
				return 'no torrents added (' . http_build_query($counts, '', ', ') . ')';
			}
		}

		return $body;
	}

	# cat and tags
	public function getCategories(): array
	{
		return $this->requestDto('GET', '/api/v2/torrents/categories', Category::class);
	}

	public function createCategory(string $name, string $savePath = '')
	{
		return $this->request('POST', '/api/v2/torrents/createCategory', [
		'form_params' => [
			'category' => $name,
			'savePath' => $savePath,
		],
		]);
	}

	public function deleteCategories(string|array $categories)
	{
		return $this->request('POST', '/api/v2/torrents/removeCategories', [
		'form_params' => [
			'categories' => implode("\n", (array)$categories),
		],
		]);
	}

	public function editCategory(string $name, string $savePath)
	{
		return $this->request('POST', '/api/v2/torrents/editCategory', [
		'form_params' => [
			'category' => $name,
			'savePath' => $savePath,
		],
		]);
	}

	public function setTorrentCategory(string|array $hashes, string $category)
	{
		return $this->request('POST', '/api/v2/torrents/setCategory', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'category' => $category,
		],
		]);
	}

	public function getTags(): array
	{
		return $this->request('GET', '/api/v2/torrents/tags');
	}

	public function addTorrentTags(string|array $hashes, string|array $tags)
	{
		return $this->request('POST', '/api/v2/torrents/addTags', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'tags' => implode(',', (array)$tags),
		],
		]);
	}

	public function removeTorrentTags(string|array $hashes, string|array $tags)
	{
		return $this->request('POST', '/api/v2/torrents/removeTags', [
		'form_params' => [
			'hashes' => implode('|', (array)$hashes),
			'tags' => implode(',', (array)$tags),
		],
		]);
	}

	public function createTags(string|array $tags)
	{
		return $this->request('POST', '/api/v2/torrents/createTags', [
		'form_params' => [
			'tags' => implode(',', (array)$tags),
		],
		]);
	}

	public function deleteTags(string|array $tags)
	{
		return $this->request('POST', '/api/v2/torrents/deleteTags', [
		'form_params' => [
			'tags' => implode(',', (array)$tags),
		],
		]);
	}

	# rss
	public function getRssFeeds(array $query = [])
	{
		return $this->hydrateRssItems($this->request('GET', '/api/v2/rss/items', [
		'query' => $query,
		]));
	}

	// /rss/items is a tree keyed by feed/folder name; a node is a feed iff it has a 'uid' key,
	// otherwise it's a folder of more nodes. $prefix accumulates the backslash-joined path so
	// each Feed knows its own full item path (the API never exposes it as a field, only as tree keys)
	private function hydrateRssItems(array $items, string $prefix = ''): array
	{
		$result = [];

		foreach ($items as $name => $item)
		{
			$path = $prefix === '' ? $name : $prefix . '\\' . $name;

			$result[$name] = array_key_exists('uid', $item)
			? new Feed(['path' => $path] + $item, $this)
			: $this->hydrateRssItems($item, $path);
		}

		return $result;
	}

	public function addRssRule(string $ruleName, array $ruleDef)
	{
		return $this->setRssRule($ruleName, $ruleDef);
	}

	public function setRssRule(string $ruleName, array $ruleDef)
	{
		return $this->request('POST', '/api/v2/rss/setRule', [
		'form_params' => [
			'ruleName' => $ruleName,
			'ruleDef' => json_encode($ruleDef),
		],
		]);
	}

	public function removeRssRule(string $ruleName)
	{
		$this->deleteRssRule($ruleName);
	}

	public function deleteRssRule(string $ruleName)
	{
		return $this->request('POST', '/api/v2/rss/removeRule', [
		'form_params' => [
			'ruleName' => $ruleName,
		],
		]);
	}

	public function addRssFeed(string $url, string $path = '')
	{
		return $this->request('POST', '/api/v2/rss/addFeed', [
		'form_params' => [
			'url' => $url,
			'path' => $path,
		],
		]);
	}

	public function removeRssFeed(string $path)
	{
		return $this->request('POST', '/api/v2/rss/removeItem', [
		'form_params' => [
			'path' => $path,
		],
		]);
	}

	public function addRssFolder(string $path)
	{
		return $this->request('POST', '/api/v2/rss/addFolder', [
		'form_params' => [
			'path' => $path,
		],
		]);
	}

	public function setRssFeedRefreshInterval(string $path, int $seconds)
	{
		return $this->request('POST', '/api/v2/rss/setFeedRefreshInterval', [
		'form_params' => [
			'path' => $path,
			'refreshInterval' => $seconds,
		],
		]);
	}

	public function setRssFeedUrl(string $path, string $url)
	{
		return $this->request('POST', '/api/v2/rss/setFeedURL', [
		'form_params' => [
			'path' => $path,
			'url' => $url,
		],
		]);
	}

	public function moveRssItem(string $itemPath, string $destPath)
	{
		return $this->request('POST', '/api/v2/rss/moveItem', [
		'form_params' => [
			'itemPath' => $itemPath,
			'destPath' => $destPath,
		],
		]);
	}

	public function markRssItemAsRead(string $itemPath, ?string $articleId = null)
	{
		$formParams = ['itemPath' => $itemPath];

		if ($articleId !== null)
		{
			$formParams['articleId'] = $articleId;
		}

		return $this->request('POST', '/api/v2/rss/markAsRead', [
		'form_params' => $formParams,
		]);
	}

	public function refreshRssItem(string $itemPath)
	{
		return $this->request('POST', '/api/v2/rss/refreshItem', [
		'form_params' => [
			'itemPath' => $itemPath,
		],
		]);
	}

	public function renameRssRule(string $ruleName, string $newRuleName)
	{
		return $this->request('POST', '/api/v2/rss/renameRule', [
		'form_params' => [
			'ruleName' => $ruleName,
			'newRuleName' => $newRuleName,
		],
		]);
	}

	// undocumented on the wiki, found in RSSController::cloneRuleAction() (rsscontroller.cpp)
	public function cloneRssRule(string $sourceName, string $cloneName)
	{
		return $this->request('POST', '/api/v2/rss/cloneRule', [
		'form_params' => [
			'sourceName' => $sourceName,
			'cloneName' => $cloneName,
		],
		]);
	}

	public function getMatchingArticles(string $ruleName): array
	{
		return $this->request('GET', '/api/v2/rss/matchingArticles', [
		'query' => [
			'ruleName' => $ruleName,
		],
		]);
	}

	# search
	public function startSearch(string $pattern, string|array $plugins = 'all', string $category = 'all'): int
	{
		$result = $this->request('POST', '/api/v2/search/start', [
		'form_params' => [
			'pattern' => $pattern,
			'plugins' => implode('|', (array)$plugins),
			'category' => $category,
		],
		]);

		return $result['id'];
	}

	public function stopSearch(int $id)
	{
		return $this->request('POST', '/api/v2/search/stop', [
		'form_params' => [
			'id' => $id,
		],
		]);
	}

	public function deleteSearch(int $id)
	{
		return $this->request('POST', '/api/v2/search/delete', [
		'form_params' => [
			'id' => $id,
		],
		]);
	}

	public function getSearchStatus(?int $id = null): array
	{
		return $this->requestDto('GET', '/api/v2/search/status', Status::class, [
		'query' => array_filter(['id' => $id], fn ($v) => $v !== null),
		]);
	}

	public function getSearchResults(int $id, ?int $limit = null, ?int $offset = null): array
	{
		return $this->hydrateSearchResults($this->request('GET', '/api/v2/search/results', [
		'query' => array_filter(
			['id' => $id, 'limit' => $limit, 'offset' => $offset],
			fn ($v) => $v !== null,
		),
		]));
	}

	private function hydrateSearchResults(array $data): array
	{
		return [
		'results' => array_map(fn ($r) => new SearchResult($r, $this), $data['results']),
		'status' => SearchStatus::from($data['status']),
		'total' => $data['total'],
		];
	}

	public function getSearchPlugins(): array
	{
		return $this->requestDto('GET', '/api/v2/search/plugins', SearchPlugin::class);
	}

	public function installSearchPlugin(string|array $sources)
	{
		return $this->request('POST', '/api/v2/search/installPlugin', [
		'form_params' => [
			'sources' => implode("\n", (array)$sources),
		],
		]);
	}

	public function uninstallSearchPlugin(string|array $names)
	{
		return $this->request('POST', '/api/v2/search/uninstallPlugin', [
		'form_params' => [
			'names' => implode('|', (array)$names),
		],
		]);
	}

	public function enableSearchPlugin(string|array $names, bool $enable)
	{
		return $this->request('POST', '/api/v2/search/enablePlugin', [
		'form_params' => [
			'names' => implode('|', (array)$names),
			'enable' => $enable ? 'true' : 'false',
		],
		]);
	}

	public function updateSearchPlugins()
	{
		return $this->request('POST', '/api/v2/search/updatePlugins');
	}

	public function downloadSearchTorrent(string $torrentUrl, string $pluginName)
	{
		return $this->request('POST', '/api/v2/search/downloadTorrent', [
		'form_params' => [
			'torrentUrl' => $torrentUrl,
			'pluginName' => $pluginName,
		],
		]);
	}

	# torrentcreator
	public function createTorrentTask(string $sourcePath, array $options = []): string
	{
		$result = $this->request('POST', '/api/v2/torrentcreator/addTask', [
		'form_params' => array_merge([
			'sourcePath' => $sourcePath,
		], $options),
		]);

		return $result['taskID'] ?? '';
	}

	public function getTorrentCreationStatus(?string $taskId = null): array
	{
		return $this->request('GET', '/api/v2/torrentcreator/status', [
		'query' => $taskId === null ? [] : ['taskID' => $taskId],
		]);
	}

	public function getTorrentCreationFile(string $taskId): string
	{
		return $this->requestRaw('GET', '/api/v2/torrentcreator/torrentFile', [
		'query' => ['taskID' => $taskId],
		]);
	}

	public function deleteTorrentCreationTask(string $taskId)
	{
		return $this->request('POST', '/api/v2/torrentcreator/deleteTask', [
		'form_params' => [
			'taskID' => $taskId,
		],
		]);
	}

	# helper
	protected function buildMultipartOptions(array $options)
	{
		$multipart = [];

		foreach ($options as $key => $value)
		{
			$multipart[] = [
			'name' => $key,
			'contents' => $value,
			];
		}

		return $multipart;
	}

	protected function toDto(
	string $class,
	array $data,
	): object|array
	{
		if (array_is_list($data))
		{
			return array_map(
			fn ($v) => new $class($v, $this),
			$data,
			);
		}

		if (is_array(reset($data)))
		{
			return array_map(
			fn ($v) => new $class($v, $this),
			$data,
			);
		}

		return new $class($data, $this);
	}

	protected function requestDto(
	string $method,
	string $uri,
	string $class,
	array $options = [],
	): object|array
	{
		return $this->toDto(
		$class,
		$this->request(
			$method,
			$uri,
			$options,
		),
		);
	}
}

