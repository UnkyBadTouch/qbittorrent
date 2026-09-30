<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent;

use Blackout\Qbittorrent\Client;
use Blackout\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Session handling: login, the cookie cache, and the 403 re-login retry.
 *
 * These paths are the reason the harness redirects authCookieCache — login()
 * mkdir()s and writes a real session token there, so an unmocked test would
 * plant a live cookie file on the developer's machine.
 */
#[CoversClass(Client::class)]
final class ClientAuthTest extends ClientTestCase
{
	public function testLoginSendsCredentialsAsFormParamsWithARefererHeader(): void
	{
		$this->queueText('', 204);

		$this->client->login('alice', 's3cret');

		$params = $this->assertRequest('POST', '/api/v2/auth/login');
		$this->assertSame('alice', $params['username']);
		$this->assertSame('s3cret', $params['password']);
		$this->assertSame(
			rtrim(self::BASE_URI, '/'),
			$this->lastRequest()->getHeaderLine('Referer'),
		);
	}

	public function testLoginStoresTheSessionCookieOn204(): void
	{
		$this->queueText('', 204,);
		$this->setPrivate($this->client, 'authCookie', '');

		// Guzzle drops a body on 204, so the cookie has to come via a header.
		$this->replaceNextResponse(
			new \GuzzleHttp\Psr7\Response(204, ['Set-Cookie' => 'SID=fresh-session; path=/'])
		);

		$this->client->login('alice', 's3cret');

		$this->assertSame('SID=fresh-session; path=/', $this->getPrivate($this->client, 'authCookie'));
	}

	public function testLoginWritesTheCookieCacheWithAnHourLongExpiry(): void
	{
		$this->replaceNextResponse(
			new \GuzzleHttp\Psr7\Response(204, ['Set-Cookie' => 'SID=fresh'])
		);

		$before = time();
		$this->client->login('alice', 's3cret');

		$cache = $this->getPrivate($this->client, 'authCookieCache');

		$this->assertFileExists($cache);

		$contents = json_decode(file_get_contents($cache), true);

		$this->assertSame('SID=fresh', $contents['cookie']);
		$this->assertGreaterThanOrEqual($before + 3599, $contents['expires']);
		$this->assertLessThanOrEqual(time() + 3601, $contents['expires']);
	}

	public function testLoginThrowsOnANonSuccessStatus(): void
	{
		$this->queueText('Fails.', 200);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Authentication failed: Fails. (200)');

		$this->client->login('alice', 'wrong');
	}

	/**
	 * A 403 never reaches login()'s own status check: Guzzle raises on the
	 * error status first, and login() rethrows that message. The distinction
	 * matters because a caller cannot tell a rejected login from a transport
	 * failure by message alone.
	 */
	public function testLoginSurfacesGuzzlesMessageOnA403(): void
	{
		$this->queueText('', 403);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('403 Forbidden');

		$this->client->login('alice', 'wrong');
	}

	public function testLoginWritesNoCacheFileWhenAuthenticationFails(): void
	{
		$this->queueText('Fails.', 200);

		try {
			$this->client->login('alice', 'wrong');
		} catch (\Exception) {
			// expected
		}

		$this->assertFileDoesNotExist($this->getPrivate($this->client, 'authCookieCache'));
	}

	public function testLoginIsFluent(): void
	{
		$this->replaceNextResponse(new \GuzzleHttp\Psr7\Response(204, ['Set-Cookie' => 'SID=x']));

		$this->assertSame($this->client, $this->client->login('alice', 's3cret'));
	}

	/**
	 * The cookie cache path is a hard-coded property default resolved inside
	 * the constructor, so it cannot be redirected before the read happens.
	 * These tests therefore write the real file — and skip rather than
	 * overwrite it if a developer's live session is sitting there.
	 */
	private function withRealCookieCache(array $contents): Client
	{
		$probe = new Client(self::BASE_URI, 'u', 'p');
		$path = $this->getPrivate($probe, 'authCookieCache');

		if (file_exists($path)) {
			$this->markTestSkipped(
				'A live cookie cache exists at ' . $path . '; refusing to overwrite it.',
			);
		}

		@mkdir(dirname($path), recursive: true);
		file_put_contents($path, json_encode($contents));

		$this->realCachePath = $path;

		return new Client(self::BASE_URI, 'u', 'p');
	}

	private ?string $realCachePath = null;

	protected function tearDown(): void
	{
		if ($this->realCachePath !== null) {
			@unlink($this->realCachePath);
			@rmdir(dirname($this->realCachePath));
			$this->realCachePath = null;
		}

		parent::tearDown();
	}

	/**
	 * A cached cookie that has not expired is adopted on construction, so the
	 * first API call does not have to re-authenticate.
	 */
	public function testAnUnexpiredCachedCookieIsAdoptedOnConstruction(): void
	{
		$client = $this->withRealCookieCache([
			'cookie'  => 'SID=cached',
			'expires' => time() + 600,
		]);

		$this->assertSame('SID=cached', $this->getPrivate($client, 'authCookie'));
	}

	public function testAnExpiredCachedCookieIsIgnoredOnConstruction(): void
	{
		$client = $this->withRealCookieCache([
			'cookie'  => 'SID=stale',
			'expires' => time() - 1,
		]);

		$this->assertSame('', $this->getPrivate($client, 'authCookie'));
	}

	public function testNoCacheFileLeavesTheClientUnauthenticated(): void
	{
		$probe = new Client(self::BASE_URI, 'u', 'p');
		$path = $this->getPrivate($probe, 'authCookieCache');

		$this->assertFileDoesNotExist(
			$path,
			'This test only means anything when no cache file is present.',
		);

		$this->assertSame('', $this->getPrivate(new Client(self::BASE_URI, 'u', 'p'), 'authCookie'));
	}

	/**
	 * With no session cookie, the first request triggers a login, then the
	 * original call goes out authenticated.
	 */
	public function testAnUnauthenticatedClientLogsInBeforeItsFirstRequest(): void
	{
		$client = $this->buildClientWithCache($this->cacheDir . '/none.json');

		// 1) login, 2) the actual call
		$this->queueText('', 204);
		$this->queueJson([]);

		$client->getTorrents();

		$this->assertRequestCount(2);
		$this->assertSame('/api/v2/auth/login', $this->history[0]['request']->getUri()->getPath());
		$this->assertSame('/api/v2/torrents/info', $this->history[1]['request']->getUri()->getPath());
	}

	/**
	 * A 403 on an authenticated call means the session expired: the client
	 * clears the cookie, re-authenticates, and replays the request once.
	 */
	public function testA403TriggersOneReLoginAndRetry(): void
	{
		$this->setPrivate($this->client, 'authCookie', 'SID=expired');

		// 1) the original call -> 403, 2) login -> 204, 3) the replay -> 200
		$this->queueException($this->requestExceptionFor(403));
		$this->queueText('', 204);
		$this->queueJson([['hash' => 'abc']]);

		$torrents = $this->client->getTorrents();

		$this->assertRequestCount(3);
		$this->assertCount(1, $torrents);
		$this->assertSame('abc', $torrents[0]->hash);
	}

	public function testTheRetriedRequestCarriesTheFreshCookie(): void
	{
		$this->setPrivate($this->client, 'authCookie', 'SID=expired');

		$this->queueException($this->requestExceptionFor(403));
		$this->queueText('', 204,);
		$this->queueJson([]);

		$this->replaceQueue(1, new \GuzzleHttp\Psr7\Response(204, ['Set-Cookie' => 'SID=renewed']));

		$this->client->getTorrents();

		$this->assertSame('SID=expired', $this->history[0]['request']->getHeaderLine('Cookie'));
		$this->assertSame('SID=renewed', $this->history[2]['request']->getHeaderLine('Cookie'));
	}

	/**
	 * The retry is bounded to one attempt: a second 403 propagates instead of
	 * looping.
	 */
	public function testASecond403IsNotRetriedAgain(): void
	{
		$this->setPrivate($this->client, 'authCookie', 'SID=expired');

		$this->queueException($this->requestExceptionFor(403));
		$this->queueText('', 204);
		$this->queueException($this->requestExceptionFor(403));

		$this->expectException(\Exception::class);

		try {
			$this->client->getTorrents();
		} finally {
			$this->assertRequestCount(3, 'One original, one login, one retry — no second retry.');
		}
	}

	public function testANon403ErrorIsNotRetried(): void
	{
		$this->queueException($this->requestExceptionFor(500));

		try {
			$this->client->getTorrents();
		} catch (\Exception) {
			// expected
		}

		$this->assertRequestCount(1, 'A 500 is not an auth problem, so it must not re-login.');
	}

	public function testLogoutPostsToTheLogoutEndpoint(): void
	{
		$this->queueText('Ok.');

		$this->client->logout();

		$this->assertRequest('POST', '/api/v2/auth/logout');
	}

	public function testLogoutClearsTheLocalSessionCookie(): void
	{
		$this->setPrivate($this->client, 'authCookie', 'SID=active');
		$this->queueText('Ok.');

		$this->client->logout();

		$this->assertSame('', $this->getPrivate($this->client, 'authCookie'));
	}

	public function testLogoutRemovesTheCookieCacheFile(): void
	{
		$cache = $this->getPrivate($this->client, 'authCookieCache');
		file_put_contents($cache, json_encode(['cookie' => 'SID=x', 'expires' => time() + 600]));

		$this->queueText('Ok.');

		$this->client->logout();

		$this->assertFileDoesNotExist($cache);
	}

	public function testClearCookiesEmptiesTheStoreWithoutTouchingTheSession(): void
	{
		$this->setPrivate($this->client, 'authCookie', 'SID=active');
		$this->queueJson([]);

		$this->client->clearCookies();

		$this->assertRequest('POST', '/api/v2/app/setCookies');
		$this->assertSame('SID=active', $this->getPrivate($this->client, 'authCookie'));
	}

	public function testSetCookiesSendsTheCookieArrayAsJson(): void
	{
		$this->queueJson([]);

		$this->client->setCookies(
            [
			['name' => 'SID', 'value' => 'abc', 'domain' => 'qbt.test', 'path' => '/'],
            ]
		);

		$params = $this->assertRequest('POST', '/api/v2/app/setCookies');
		$sent = json_decode($params['cookies'], true);

		$this->assertSame('SID', $sent[0]['name']);
		$this->assertSame('abc', $sent[0]['value']);
	}
}
