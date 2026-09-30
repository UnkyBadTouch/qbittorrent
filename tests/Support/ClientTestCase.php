<?php

declare(strict_types=1);

namespace Blackout\Tests\Support;

use Blackout\Qbittorrent\Client;
use GuzzleHttp\Client as Http;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * Base class for tests that exercise Client against a mocked transport.
 *
 * Client builds its own GuzzleHttp\Client in the constructor and keeps it in a
 * private property, so the handler stack is swapped in by reflection rather
 * than by a production-code seam. The replacement client mirrors the real
 * constructor's config exactly (base_uri/timeout/verify) so that URI
 * resolution and option merging behave identically to production.
 *
 * Two pieces of state must also be neutralised, or the suite writes to the
 * developer's real machine:
 *
 *  - authCookie is pre-set so checkAuthenticated() never triggers a login POST.
 *  - authCookieCache is redirected into a per-test temp dir, because
 *    Client::login() mkdir()s and writes a live session token there.
 */
abstract class ClientTestCase extends TestCase
{
	protected const BASE_URI = 'http://qbt.test:8080';

	protected QueueHandler $handler;

	protected HandlerStack $stack;

	/** @var array<int, array{request: RequestInterface, response: Response|null, error: mixed, options: array}> */
	protected array $history = [];

	protected string $cacheDir;

	/**
	 * Path to a minimal on-disk .torrent body, for the endpoints that fopen()
	 * a real file (parseMetadata). Created once per process and removed at
	 * shutdown, so a data provider can hand it to a test without depending on
	 * setUp() having run.
	 */
	protected static function torrentFixture(): string
	{
		static $path = null;

		if ($path === null) {
			$path = sys_get_temp_dir() . '/qbt-fixture-' . getmypid() . '.torrent';
			file_put_contents($path, "d4:infod4:name1:ae");

			register_shutdown_function(static function () use ($path): void {
				@unlink($path);
			});
		}

		return $path;
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->handler = new QueueHandler();
		$this->history = [];

		$this->stack = HandlerStack::create($this->handler);
		$this->stack->push(Middleware::history($this->history));

		$this->cacheDir = sys_get_temp_dir() . '/qbt-test-' . bin2hex(random_bytes(6));
		mkdir($this->cacheDir, recursive: true);

		$this->client = new Client(self::BASE_URI, 'testuser', 'testpass');

		$this->setPrivate($this->client, 'http', new Http(
            [
			'handler'   => $this->stack,
			'base_uri'  => rtrim(self::BASE_URI, '/') . '/',
			'timeout'   => 10,
			'verify'    => true,
            ]
        ));
		$this->setPrivate($this->client, 'authCookie', 'SID=mocked-session');
		$this->setPrivate($this->client, 'authCookieCache', $this->cacheDir . '/cookies.json');
	}
	protected function tearDown(): void
	{
		foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
			@unlink($file);
		}

		@rmdir($this->cacheDir);

		parent::tearDown();
	}

	protected Client $client;

	protected function setPrivate(object $object, string $property, mixed $value): void
	{
		(new ReflectionProperty($object, $property))->setValue($object, $value);
	}

	protected function getPrivate(object $object, string $property): mixed
	{
		return (new ReflectionProperty($object, $property))->getValue($object);
	}

	/**
	 * Queue a JSON response, decoded by Client into an array.
	 */
	protected function queueJson(mixed $data, int $status = 200): void
	{
		$this->append(
			new Response(
				$status,
				['Content-Type' => 'application/json'],
				json_encode($data, JSON_THROW_ON_ERROR),
			)
		);
	}

	/**
	 * Queue a plain-text response, as the /torrents/add and logout endpoints
	 * return rather than JSON.
	 */
	protected function queueText(string $body, int $status = 200): void
	{
		$this->append(new Response($status, [], $body));
	}

	/**
	 * Queue a response that the transport fails with, standing in for a
	 * connection error or a status Guzzle raises as an exception.
	 */
	protected function queueException(\Throwable $exception): void
	{
		$this->append($exception);
	}

	/** Record a queued response, keeping it editable via replaceQueue(). */
	private function append(Response|\Throwable $item): void
	{
		$this->handler->append($item);
	}

	/**
	 * Queue a response whose JSON shape suits the called method's return type.
	 *
	 * toDto() branches on the decoded payload: a list (or a map of arrays)
	 * becomes a collection of DTOs, anything else becomes one DTO. A method
	 * declared `: Properties` would trip its return type on a list, so the
	 * contract test picks the shape from the flag rather than guessing.
	 */
	protected function queueResponseFor(bool $singleDto): void
	{
		$this->queueJson($singleDto ? ['name' => 'fixture'] : [['name' => 'fixture']]);
	}

	protected function lastRequest(): RequestInterface
	{
		$this->assertNotEmpty($this->history, 'No request was sent.');

		return $this->history[count($this->history) - 1]['request'];
	}

	protected function requestCount(): int
	{
		return count($this->history);
	}

	protected function assertRequestCount(int $expected): void
	{
		$this->assertSame($expected, $this->requestCount());
	}

	/** Parse the form_params of the most recent request. */
	protected function lastFormParams(): array
	{
		$body = (string) $this->lastRequest()->getBody();

		parse_str($body, $params);

		return $params;
	}

	/** Parse the query string of the most recent request. */
	protected function lastQueryParams(): array
	{
		parse_str($this->lastRequest()->getUri()->getQuery(), $params);

		return $params;
	}

	protected function lastUriPath(): string
	{
		return $this->lastRequest()->getUri()->getPath();
	}

	protected function lastMethod(): string
	{
		return $this->lastRequest()->getMethod();
	}

	/**
	 * The path a request resolved to, relative to base_uri, which is the form
	 * the Client's own URIs are written in.
	 */
	protected function relativeUriPath(): string
	{
		$path = $this->lastUriPath();
		$base = parse_url(self::BASE_URI, PHP_URL_PATH) ?: '/';
		$base = '/' . trim($base, '/');

		if ($base !== '/' && str_starts_with($path, $base)) {
			$path = substr($path, strlen($base));
		}

		return '/' . ltrim($path, '/');
	}

	/**
	 * Assert the most recent request hit a specific method and endpoint, and
	 * return its form params for further assertions.
	 */
	protected function assertRequest(string $method, string $uri): array
	{
		$this->assertSame($method, $this->lastMethod(), 'Unexpected HTTP method.');
		$this->assertSame($uri, $this->relativeUriPath(), 'Unexpected endpoint.');

		return $this->lastFormParams();
	}

	/**
	 * Swap the response at a queued position, for tests that need specific
	 * headers on a response queueText()/queueJson() cannot express.
	 */
	protected function replaceQueue(int $index, Response $response): void
	{
		$this->handler->replace($index, $response);
	}

	/** The same, for the next response the client will consume. */
	protected function replaceNextResponse(Response $response): void
	{
		$this->replaceQueue(0, $response);
	}

	protected function handler(): QueueHandler
	{
		return $this->handler;
	}

	/**
	 * A second Client sharing this test's mock handler, pointed at a specific
	 * cookie-cache path. Used to test what the constructor does with a cache
	 * file, which cannot be arranged through the already-built instance.
	 */
	protected function buildClientWithCache(string $cachePath): Client
	{
		$client = new Client(self::BASE_URI, 'testuser', 'testpass');

		$this->setPrivate($client, 'authCookieCache', $cachePath);
		$this->setPrivate($client, 'http', new Http(
            [
			'handler'  => $this->stack,
			'base_uri' => rtrim(self::BASE_URI, '/') . '/',
            ]
        ));

		return $client;
	}

	/**
	 * Build the PSR-7 request a queued failure should carry, for constructing
	 * the RequestException that Guzzle raises for error statuses.
	 */
	protected function requestExceptionFor(
		int $status,
		string $body = '',
		string $method = 'GET',
		string $uri = '/api/v2/app/version',
	): \GuzzleHttp\Exception\RequestException {
		return new \GuzzleHttp\Exception\RequestException(
			'Client error: ' . $status,
			new Request($method, self::BASE_URI . $uri),
			new Response($status, [], $body),
		);
	}
}
