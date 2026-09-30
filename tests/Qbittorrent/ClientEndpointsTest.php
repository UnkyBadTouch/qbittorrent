<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent;

use Blackout\Qbittorrent\Client;
use Blackout\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Contract coverage for Client's thin endpoint wrappers.
 *
 * Most of Client's 140 public methods do nothing but forward to
 * request()/requestRaw()/requestDto() with a fixed HTTP method and endpoint.
 * Their entire contract is "this verb, this path" — so they are asserted as a
 * table rather than as 84 near-identical test methods. The methods carrying
 * real logic (auth, retries, response unwrapping, multipart) are hand-written
 * in ClientTest.
 *
 * A row reads [method name, argument list, HTTP verb, endpoint, returns a
 * single DTO]; the argument list is splatted into the call, and the flag picks
 * an object- or list-shaped response so return types are satisfied. The table
 * was generated from Client.php and is meant to be read against it — when a
 * wrapper's endpoint changes, its row is the thing to update.
 */
#[CoversClass(Client::class)]
final class ClientEndpointsTest extends ClientTestCase
{
	#[DataProvider('endpointProvider')]
	public function testWrapperHitsItsEndpoint(
		string $method,
		array $args,
		string $verb,
		string $uri,
		bool $singleDto,
	): void {
		$this->queueResponseFor($singleDto);

		$this->client->$method(...$args);

		$this->assertSame($verb, $this->lastMethod(), 'HTTP verb');
		$this->assertSame($uri, $this->relativeUriPath(), 'endpoint');
		$this->assertRequestCount(1);
	}

	/**
	 * Every call is authenticated: the session cookie rides along on each
	 * request, so no wrapper can silently skip it.
	 */
	#[DataProvider('endpointProvider')]
	public function testWrapperSendsTheSessionCookie(
		string $method,
		array $args,
		string $verb,
		string $uri,
		bool $singleDto,
	): void {
		unset($verb, $uri);

		$this->queueResponseFor($singleDto);

		$this->client->$method(...$args);

		$this->assertSame(
			'SID=mocked-session',
			$this->lastRequest()->getHeaderLine('Cookie'),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: array, 2: string, 3: string, 4: bool}>
	 */
	public static function endpointProvider(): array
	{
		return [		'getTorrentFiles' => ['getTorrentFiles', ['abc'], 'GET', '/api/v2/torrents/files', false],
		'setPreferences' => ['setPreferences', [['a' => 1]], 'POST', '/api/v2/app/setPreferences', false],
		'getDirectoryContent' => ['getDirectoryContent', ['/data'], 'GET', '/api/v2/app/getDirectoryContent', false],
		'storeClientData' => ['storeClientData', [['a' => 1]], 'POST', '/api/v2/clientdata/store', false],
		'setSpeedLimitsMode' => ['setSpeedLimitsMode', [true], 'POST', '/api/v2/transfer/setSpeedLimitsMode', false],
		'setSpeedLimits' => ['setSpeedLimits', [1, 2, 3, 4], 'POST', '/api/v2/transfer/setSpeedLimits', false],
		'setGlobalDownloadLimit' => ['setGlobalDownloadLimit', [1048576], 'POST', '/api/v2/transfer/setDownloadLimit', false],
		'setGlobalUploadLimit' => ['setGlobalUploadLimit', [1048576], 'POST', '/api/v2/transfer/setUploadLimit', false],
		'banPeers' => ['banPeers', ['1.2.3.4'], 'POST', '/api/v2/transfer/banPeers', false],
		'getTorrentProperties' => ['getTorrentProperties', ['abc'], 'GET', '/api/v2/torrents/properties', true],
		'getTorrentTrackers' => ['getTorrentTrackers', ['abc'], 'GET', '/api/v2/torrents/trackers', false],
		'getTorrentWebSeeds' => ['getTorrentWebSeeds', ['abc'], 'GET', '/api/v2/torrents/webseeds', false],
		'getTorrentPieceHashes' => ['getTorrentPieceHashes', ['abc'], 'GET', '/api/v2/torrents/pieceHashes', false],
		'getTorrentPieceAvailability' => ['getTorrentPieceAvailability', ['abc'], 'GET', '/api/v2/torrents/pieceAvailability', false],
		'addWebSeeds' => ['addWebSeeds', ['abc', 'https://a.test/'], 'POST', '/api/v2/torrents/addWebSeeds', false],
		'editWebSeed' => ['editWebSeed', ['abc', 'https://a.test/', 'https://b.test/'], 'POST', '/api/v2/torrents/editWebSeed', false],
		'removeWebSeeds' => ['removeWebSeeds', ['abc', 'https://a.test/'], 'POST', '/api/v2/torrents/removeWebSeeds', false],
		'exportTorrent' => ['exportTorrent', ['abc'], 'GET', '/api/v2/torrents/export', false],
		'downloadFile' => ['downloadFile', ['abc', '0'], 'GET', '/api/v2/torrents/downloadFile', false],
		'fetchMetadata' => ['fetchMetadata', ['https://a.test/x.torrent'], 'POST', '/api/v2/torrents/fetchMetadata', false],
		'parseMetadata' => ['parseMetadata', [self::torrentFixture()], 'POST', '/api/v2/torrents/parseMetadata', false],
		'saveMetadata' => ['saveMetadata', ['https://a.test/x.torrent'], 'GET', '/api/v2/torrents/saveMetadata', false],
		'setTorrentComment' => ['setTorrentComment', ['abc', 'hi'], 'POST', '/api/v2/torrents/setComment', false],
		'setTorrentDownloadPath' => ['setTorrentDownloadPath', ['0', '/data'], 'POST', '/api/v2/torrents/setDownloadPath', false],
		'setTorrentSavePath' => ['setTorrentSavePath', ['0', '/data'], 'POST', '/api/v2/torrents/setSavePath', false],
		'setTorrentTags' => ['setTorrentTags', ['abc', 'iso'], 'POST', '/api/v2/torrents/setTags', false],
		'getTorrentSslParameters' => ['getTorrentSslParameters', ['abc'], 'GET', '/api/v2/torrents/SSLParameters', false],
		'setTorrentSslParameters' => [
			'setTorrentSslParameters',
			['abc', 'cert', 'key', 'dh'],
			'POST',
			'/api/v2/torrents/setSSLParameters',
			false,
		],
		'stop' => ['stop', ['abc'], 'POST', '/api/v2/torrents/stop', false],
		'start' => ['start', ['abc'], 'POST', '/api/v2/torrents/start', false],
		'reannounce' => ['reannounce', ['abc'], 'POST', '/api/v2/torrents/reannounce', false],
		'delete' => ['delete', ['abc'], 'POST', '/api/v2/torrents/delete', false],
		'recheck' => ['recheck', ['abc'], 'POST', '/api/v2/torrents/recheck', false],
		'addTrackers' => ['addTrackers', ['abc', 'https://a.test/'], 'POST', '/api/v2/torrents/addTrackers', false],
		'editTracker' => ['editTracker', ['abc', 'https://a.test/', 'https://b.test/'], 'POST', '/api/v2/torrents/editTracker', false],
		'removeTrackers' => ['removeTrackers', ['abc', 'https://a.test/'], 'POST', '/api/v2/torrents/removeTrackers', false],
		'addPeers' => ['addPeers', ['abc', '1.2.3.4'], 'POST', '/api/v2/torrents/addPeers', false],
		'increasePrio' => ['increasePrio', ['abc'], 'POST', '/api/v2/torrents/increasePrio', false],
		'decreasePrio' => ['decreasePrio', ['abc'], 'POST', '/api/v2/torrents/decreasePrio', false],
		'topPrio' => ['topPrio', ['abc'], 'POST', '/api/v2/torrents/topPrio', false],
		'bottomPrio' => ['bottomPrio', ['abc'], 'POST', '/api/v2/torrents/bottomPrio', false],
		'setFilePrio' => ['setFilePrio', ['abc', '0', 1], 'POST', '/api/v2/torrents/filePrio', false],
		'getDownloadLimit' => ['getDownloadLimit', ['abc'], 'POST', '/api/v2/torrents/downloadLimit', false],
		'setDownloadLimit' => ['setDownloadLimit', ['abc', 1048576], 'POST', '/api/v2/torrents/setDownloadLimit', false],
		'getUploadLimit' => ['getUploadLimit', ['abc'], 'POST', '/api/v2/torrents/uploadLimit', false],
		'setUploadLimit' => ['setUploadLimit', ['abc', 1048576], 'POST', '/api/v2/torrents/setUploadLimit', false],
		'setShareLimits' => ['setShareLimits', ['abc', 2.0, 3600, 1800], 'POST', '/api/v2/torrents/setShareLimits', false],
		'setLocation' => ['setLocation', ['abc', '/mnt'], 'POST', '/api/v2/torrents/setLocation', false],
		'renameTorrent' => ['renameTorrent', ['abc', 'linux'], 'POST', '/api/v2/torrents/rename', false],
		'renameFile' => ['renameFile', ['abc', 'a.mkv', 'b.mkv'], 'POST', '/api/v2/torrents/renameFile', false],
		'renameFolder' => ['renameFolder', ['abc', 'a.mkv', 'b.mkv'], 'POST', '/api/v2/torrents/renameFolder', false],
		'setAutoManagement' => ['setAutoManagement', ['abc', true], 'POST', '/api/v2/torrents/setAutoManagement', false],
		'toggleSequentialDownload' => ['toggleSequentialDownload', ['abc'], 'POST', '/api/v2/torrents/toggleSequentialDownload', false],
		'toggleFirstLastPiecePrio' => ['toggleFirstLastPiecePrio', ['abc'], 'POST', '/api/v2/torrents/toggleFirstLastPiecePrio', false],
		'setForceStart' => ['setForceStart', ['abc', true], 'POST', '/api/v2/torrents/setForceStart', false],
		'setSuperSeeding' => ['setSuperSeeding', ['abc', true], 'POST', '/api/v2/torrents/setSuperSeeding', false],
		'createCategory' => ['createCategory', ['linux'], 'POST', '/api/v2/torrents/createCategory', false],
		'deleteCategories' => ['deleteCategories', ['linux'], 'POST', '/api/v2/torrents/removeCategories', false],
		'editCategory' => ['editCategory', ['linux', '/downloads'], 'POST', '/api/v2/torrents/editCategory', false],
		'setTorrentCategory' => ['setTorrentCategory', ['abc', 'linux'], 'POST', '/api/v2/torrents/setCategory', false],
		'addTorrentTags' => ['addTorrentTags', ['abc', 'iso'], 'POST', '/api/v2/torrents/addTags', false],
		'removeTorrentTags' => ['removeTorrentTags', ['abc', 'iso'], 'POST', '/api/v2/torrents/removeTags', false],
		'createTags' => ['createTags', ['iso'], 'POST', '/api/v2/torrents/createTags', false],
		'deleteTags' => ['deleteTags', ['iso'], 'POST', '/api/v2/torrents/deleteTags', false],
		'setRssRule' => ['setRssRule', ['rule', ['enabled' => true]], 'POST', '/api/v2/rss/setRule', false],
		'deleteRssRule' => ['deleteRssRule', ['rule'], 'POST', '/api/v2/rss/removeRule', false],
		'addRssFeed' => ['addRssFeed', ['https://a.test/'], 'POST', '/api/v2/rss/addFeed', false],
		'removeRssFeed' => ['removeRssFeed', ['/data'], 'POST', '/api/v2/rss/removeItem', false],
		'addRssFolder' => ['addRssFolder', ['/data'], 'POST', '/api/v2/rss/addFolder', false],
		'setRssFeedRefreshInterval' => ['setRssFeedRefreshInterval', ['/data', 3600], 'POST', '/api/v2/rss/setFeedRefreshInterval', false],
		'setRssFeedUrl' => ['setRssFeedUrl', ['/data', 'https://a.test/'], 'POST', '/api/v2/rss/setFeedURL', false],
		'moveRssItem' => ['moveRssItem', ['/feed', '/dest'], 'POST', '/api/v2/rss/moveItem', false],
		'refreshRssItem' => ['refreshRssItem', ['/feed'], 'POST', '/api/v2/rss/refreshItem', false],
		'renameRssRule' => ['renameRssRule', ['rule', 'renamed'], 'POST', '/api/v2/rss/renameRule', false],
		'cloneRssRule' => ['cloneRssRule', ['rule', 'copy'], 'POST', '/api/v2/rss/cloneRule', false],
		'getMatchingArticles' => ['getMatchingArticles', ['rule'], 'GET', '/api/v2/rss/matchingArticles', false],
		'stopSearch' => ['stopSearch', [1], 'POST', '/api/v2/search/stop', false],
		'deleteSearch' => ['deleteSearch', [1], 'POST', '/api/v2/search/delete', false],
		'installSearchPlugin' => ['installSearchPlugin', ['plugin'], 'POST', '/api/v2/search/installPlugin', false],
		'uninstallSearchPlugin' => ['uninstallSearchPlugin', ['plugin'], 'POST', '/api/v2/search/uninstallPlugin', false],
		'enableSearchPlugin' => ['enableSearchPlugin', ['plugin', true], 'POST', '/api/v2/search/enablePlugin', false],
		'downloadSearchTorrent' => [
			'downloadSearchTorrent',
			['https://a.test/x.torrent', 'plugin'],
			'POST',
			'/api/v2/search/downloadTorrent',
			false,
		],
		'getTorrentCreationFile' => ['getTorrentCreationFile', ['task-1'], 'GET', '/api/v2/torrentcreator/torrentFile', false],
		'deleteTorrentCreationTask' => ['deleteTorrentCreationTask', ['task-1'], 'POST', '/api/v2/torrentcreator/deleteTask', false],
		];
	}
}
