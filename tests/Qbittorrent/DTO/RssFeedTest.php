<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Rss\Feed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Feed::class)]
final class RssFeedTest extends TestCase
{
	public function testHydratesTheFieldsPresentWithData(): void
	{
		$feed = new Feed(
            [
			'path'             => 'Linux',
			'uid'              => 'feed-1',
			'url'              => 'https://a.test/rss',
			'title'            => 'Linux news',
			'lastBuildDate'    => 'Mon, 15 Jun 2025 12:00:00 +0000',
			'isLoading'        => false,
			'hasError'         => true,
			'refreshInterval'  => 3600,
			'articles'         => [['title' => 'Post']],
            ]
		);

		$this->assertSame('Linux', $feed->path);
		$this->assertSame('feed-1', $feed->uid);
		$this->assertSame('Linux news', $feed->title);
		$this->assertTrue($feed->hasError);
		$this->assertSame(3600, $feed->refreshInterval);
		$this->assertCount(1, $feed->articles);
	}

	/**
	 * The API only emits refreshInterval when it is greater than zero, so an
	 * absent key must leave the property uninitialised rather than defaulting.
	 */
	public function testRefreshIntervalStaysAbsentWhenTheApiOmitsIt(): void
	{
		$feed = new Feed(['path' => 'Linux', 'uid' => 'f1']);

		$this->assertArrayNotHasKey('refreshInterval', $feed->jsonSerialize());
	}

	/**
	 * A feed has no real name or unread fields — those come from the tree key
	 * and the item nesting, so the DTO must not pretend to have them.
	 */
	public function testFeedHasNoNameOrUnreadProperties(): void
	{
		$feed = new Feed(['path' => 'Linux', 'uid' => 'f1']);

		$this->assertFalse(property_exists($feed, 'name'));
		$this->assertFalse(property_exists($feed, 'unread'));
	}

	public function testMoveForwardsBothPathsAndUpdatesTheLocalOne(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('moveRssItem')
			->with('Linux', 'Archive/Linux');

		$feed = new Feed(['path' => 'Linux', 'uid' => 'f1'], $client);

		$feed->move('Archive/Linux');

		$this->assertSame('Archive/Linux', $feed->path);
	}

	public function testSetUrlForwardsThePathAndUpdatesTheLocalField(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('setRssFeedUrl')
			->with('Linux', 'https://b.test/rss');

		$feed = new Feed(['path' => 'Linux', 'url' => 'https://a.test/rss'], $client);

		$feed->setUrl('https://b.test/rss');

		$this->assertSame('https://b.test/rss', $feed->url);
	}

	public function testSetRefreshIntervalForwardsAndUpdatesTheLocalField(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('setRssFeedRefreshInterval')
			->with('Linux', 1800);

		$feed = new Feed(['path' => 'Linux'], $client);

		$feed->setRefreshInterval(1800);

		$this->assertSame(1800, $feed->refreshInterval);
	}

	public function testMarkAsReadPassesNullForTheWholeFeed(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('markRssItemAsRead')
			->with('Linux', null);

		(new Feed(['path' => 'Linux'], $client))->markAsRead();
	}

	public function testMarkAsReadForwardsAnArticleId(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('markRssItemAsRead')
			->with('Linux', 'article-9');

		(new Feed(['path' => 'Linux'], $client))->markAsRead('article-9');
	}

	public function testRefreshForwardsThePath(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('refreshRssItem')
			->with('Linux');

		(new Feed(['path' => 'Linux'], $client))->refresh();
	}

	public function testRemoveForwardsThePath(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('removeRssFeed')
			->with('Linux');

		(new Feed(['path' => 'Linux'], $client))->remove();
	}
}
