<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Rss\Feed;
use Blackout\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The endpoints whose response is not a plain verb-and-path forward: /torrents/add
 * (text or JSON, two success shapes), /rss/items (a nested tree), and
 * /torrentcreator/addTask (a JSON envelope).
 */
#[CoversClass(Client::class)]
final class ClientAddTorrentTest extends ClientTestCase
{
	public function testAddTorrentUrlsReturnsThePlainTextOkResponse(): void
	{
		$this->queueText('Ok.');

		$this->assertSame('Ok.', $this->client->addTorrentUrls('magnet:?xt=urn:btih:abc'));
	}

	public function testAddTorrentUrlsJoinsMultipleUrlsWithNewlines(): void
	{
		$this->queueText('Ok.');

		$this->client->addTorrentUrls(['magnet:?a', 'magnet:?b']);

		$params = $this->assertRequest('POST', '/api/v2/torrents/add');
		$this->assertSame("magnet:?a\nmagnet:?b", $params['urls']);
	}

	/**
	 * Some proxy/tracker paths answer with a JSON success payload instead of
	 * "Ok." — that is still a success and must not be reported as a failure.
	 */
	public function testAddTorrentUrlsAcceptsAJsonSuccessPayload(): void
	{
		$this->queueText('{"added_torrent_ids":[],"success_count":1,"failure_count":0}');

		$this->assertSame(
			'{"added_torrent_ids":[],"success_count":1,"failure_count":0}',
			$this->client->addTorrentUrls('magnet:?xt=urn:btih:abc'),
		);
	}

	/**
	 * A payload with no failures is a success even when success_count is 0.
	 */
	public function testAddTorrentUrlsAcceptsZeroSuccessesWithNoFailures(): void
	{
		$this->queueText('{"success_count":0,"failure_count":0}');

		$this->assertSame(
			'{"success_count":0,"failure_count":0}',
			$this->client->addTorrentUrls('magnet:?xt=urn:btih:abc'),
		);
	}

	#[DataProvider('failureProvider')]
	public function testAddTorrentUrlsThrowsWithTheServersReason(string $body, string $expectedReason): void
	{
		$this->queueText($body);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Add torrent failed: ' . $expectedReason);

		$this->client->addTorrentUrls('magnet:?xt=urn:btih:abc');
	}

	public static function failureProvider(): array
	{
		return [
			'plain fails'      => ['Fails.', 'Fails.'],
			'json error'       => ['{"error":"Torrent link is invalid"}', 'Torrent link is invalid'],
			'json message'     => ['{"message":"Duplicate torrent"}', 'Duplicate torrent'],
			'json reason'      => ['{"reason":"Tracker unreachable"}', 'Tracker unreachable'],
			// No error/message/reason key, so the counts are summarised instead
			// of dumping the raw payload at the caller.
			'counts only' => [
				'{"success_count":0,"failure_count":2}',
				'no torrents added (success_count=0, failure_count=2)',
			],
			'empty body'       => ['', '(empty response)'],
		];
	}

	/**
	 * error wins over message, and message over reason, so the most specific
	 * reason the server gave is the one reported.
	 */
	public function testTheErrorKeyIsPreferredOverMessageAndReason(): void
	{
		$this->queueText('{"reason":"r","message":"m","error":"e"}');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Add torrent failed: e');

		$this->client->addTorrentUrls('magnet:?xt=urn:btih:abc');
	}

	public function testAddTorrentUrlsMergesCallerOptionsIntoTheForm(): void
	{
		$this->queueText('Ok.');

		// savepath is deliberately not passed by this client, but a caller can.
		$this->client->addTorrentUrls('magnet:?xt=urn:btih:abc', [
			'savepath'  => '/data',
			'category'  => 'linux',
		]);

		$params = $this->assertRequest('POST', '/api/v2/torrents/add');
		$this->assertSame('/data', $params['savepath']);
		$this->assertSame('linux', $params['category']);
		$this->assertSame('magnet:?xt=urn:btih:abc', $params['urls']);
	}

	public function testAddTorrentFileReturnsTheOkResponse(): void
	{
		$this->queueText('Ok.');

		$this->assertSame('Ok.', $this->client->addTorrentFile(self::torrentFixture()));
	}

	public function testAddTorrentFileUploadsTheFileAsMultipart(): void
	{
		$this->queueText('Ok.');

		$this->client->addTorrentFile(self::torrentFixture());

		$this->assertRequest('POST', '/api/v2/torrents/add');
		$this->assertStringContainsString(
			'multipart/form-data',
			$this->lastRequest()->getHeaderLine('Content-Type'),
		);
		$this->assertStringContainsString(
			'name="torrents"',
			(string) $this->lastRequest()->getBody(),
		);
	}

	public function testAddTorrentFileAppendsTheExtensionOnlyWhenMissing(): void
	{
		$dir = $this->cacheDir;
		$withExt = $dir . '/already.torrent';
		$withoutExt = $dir . '/noext';
		file_put_contents($withExt, 'd1:ae');
		file_put_contents($withoutExt, 'd1:ae');

		$this->queueText('Ok.');
		$this->client->addTorrentFile($withExt);
		$this->assertStringContainsString(
			'filename="already.torrent"',
			(string) $this->lastRequest()->getBody(),
		);

		$this->queueText('Ok.');
		$this->client->addTorrentFile($withoutExt);
		$this->assertStringContainsString(
			'filename="noext.torrent"',
			(string) $this->lastRequest()->getBody(),
		);
	}

	public function testAddTorrentFileThrowsOnFailure(): void
	{
		$this->queueText('{"error":"Cannot read torrent file"}');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Add torrent failed: Cannot read torrent file');

		$this->client->addTorrentFile(self::torrentFixture());
	}

	/**
	 * /rss/items is a tree: a node carrying a `uid` is a feed, anything else is
	 * a folder whose children are keyed by name. Folder paths are built with a
	 * backslash separator, matching qBittorrent's own item paths.
	 */
	public function testGetRssFeedsSplitsFeedsFromFolders(): void
	{
		$this->queueJson(
            [
			'Linux' => [
				'uid'  => 'feed-1',
				'url'  => 'https://a.test/rss',
				'title' => 'Linux news',
			],
			'Nested' => [
				'Inner' => [
					'uid' => 'feed-2',
					'url' => 'https://b.test/rss',
				],
			],
            ]
		);

		$items = $this->client->getRssFeeds();

		$this->assertInstanceOf(Feed::class, $items['Linux']);
		$this->assertSame('feed-1', $items['Linux']->uid);
		$this->assertSame('Linux news', $items['Linux']->title);

		$this->assertIsArray($items['Nested'], 'A node with no uid is a folder.');
		$this->assertInstanceOf(Feed::class, $items['Nested']['Inner']);
		$this->assertSame('Nested\\Inner', $items['Nested']['Inner']->path);
	}

	public function testGetRssFeedsInjectsThePathFromTheTreePosition(): void
	{
		$this->queueJson(['Top level' => ['uid' => 'f1', 'url' => 'https://a.test/']]);

		$this->assertSame('Top level', $this->client->getRssFeeds()['Top level']->path);
	}

	public function testGetRssFeedsBuildsDeeperFolderPaths(): void
	{
		$this->queueJson(
            [
			'A' => [
				'B' => [
					'C' => ['uid' => 'deep', 'url' => 'https://a.test/'],
				],
			],
            ]
		);

		$items = $this->client->getRssFeeds();

		$this->assertSame('A\\B\\C', $items['A']['B']['C']->path);
	}

	/**
	 * A feed's name and unread count come from the tree key and nesting, never
	 * from the API — /rss/items has no such fields.
	 */
	public function testFeedPayloadsCarryNoNameOrUnreadField(): void
	{
		$this->queueJson(['My feed' => ['uid' => 'f1', 'url' => 'https://a.test/']]);

		$feed = $this->client->getRssFeeds()['My feed'];

		$this->assertArrayNotHasKey('name', $feed->jsonSerialize());
		$this->assertArrayNotHasKey('unread', $feed->jsonSerialize());
		$this->assertSame('My feed', $feed->path);
	}

	public function testGetRssFeedsWithDataIncludesPerFeedFields(): void
	{
		$this->queueJson(
            [
			'Feed' => [
				'uid'              => 'f1',
				'url'              => 'https://a.test/',
				'title'            => 'A title',
				'lastBuildDate'    => 'Mon, 15 Jun 2025 12:00:00 +0000',
				'isLoading'        => false,
				'hasError'         => false,
				'refreshInterval'  => 3600,
				'articles'         => [['title' => 'Post']],
			],
            ]
		);

		$feed = $this->client->getRssFeeds(['withData' => 'true'])['Feed'];

		$this->assertSame('A title', $feed->title);
		$this->assertSame(3600, $feed->refreshInterval);
		$this->assertCount(1, $feed->articles);
	}

	public function testRefreshIntervalIsAbsentWhenTheApiOmitsIt(): void
	{
		$this->queueJson(['Feed' => ['uid' => 'f1', 'url' => 'https://a.test/']]);

		$feed = $this->client->getRssFeeds()['Feed'];

		$this->assertArrayNotHasKey('refreshInterval', $feed->jsonSerialize());
	}

	/**
	 * addTask answers {"taskID": "..."}; callers want the id, and an absent one
	 * degrades to an empty string rather than an undefined-index error.
	 */
	public function testCreateTorrentTaskUnwrapsTheTaskId(): void
	{
		$this->queueJson(['taskID' => 'task-abc']);

		$this->assertSame('task-abc', $this->client->createTorrentTask('/data/movie'));
	}

	public function testCreateTorrentTaskReturnsEmptyStringWhenNoIdIsReturned(): void
	{
		$this->queueJson([]);

		$this->assertSame('', $this->client->createTorrentTask('/data/movie'));
	}

	public function testCreateTorrentTaskSendsTheSourcePathAndOptions(): void
	{
		$this->queueJson(['taskID' => 't1']);

		$this->client->createTorrentTask('/data/movie', [
			'trackers' => 'https://t.test/announce',
			'category' => 'movies',
		]);

		$params = $this->assertRequest('POST', '/api/v2/torrentcreator/addTask');
		$this->assertSame('/data/movie', $params['sourcePath']);
		$this->assertSame('https://t.test/announce', $params['trackers']);
		$this->assertSame('movies', $params['category']);
	}

	public function testGetTorrentCreationStatusReturnsTheRawShape(): void
	{
		$this->queueJson(
            [
			[
				'taskID'     => 't1',
				'status'     => 'Completed',
				'format'     => 'v1',
				'torrentContentLayout' => 'Original',
			],
            ]
		);

		$status = $this->client->getTorrentCreationStatus('t1');

		$this->assertSame('Completed', $status[0]['status']);
	}
}
