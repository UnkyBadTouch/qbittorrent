<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Torrent;
use Blackout\Qbittorrent\DTO\Torrent\File;
use Blackout\Qbittorrent\DTO\Torrent\Peer;
use Blackout\Qbittorrent\DTO\Torrent\Tracker;
use Blackout\Qbittorrent\DTO\Torrent\WebSeed;
use Blackout\Qbittorrent\Enum\FilePriority;
use Blackout\Qbittorrent\Enum\ShareLimitAction;
use Blackout\Qbittorrent\Enum\ShareLimitsMode;
use Blackout\Qbittorrent\Enum\TorrentState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Torrent::class)]
final class TorrentTest extends TestCase
{
	private Client $client;

	protected function setUp(): void
	{
		parent::setUp();

		// A stub, not a mock: most of these tests only need a client that will
		// not be called at all (hydration and computed fields are pure). Tests
		// that do assert on calls set their own expectations.
		$this->client = $this->createStub(Client::class);
	}

	public function testHydratesFromATorrentsInfoPayload(): void
	{
		$torrent = new Torrent(
            [
			'hash'         => 'abc123',
			'name'         => 'ubuntu-24.04.iso',
			'state'        => 'downloading',
			'progress'     => 0.42,
			'size'         => 6291456,
			'category'     => 'linux',
			'tags'         => 'iso,release',
			'save_path'    => '/downloads',
			'num_seeds'    => 12,
			'eta'          => 8640000,
			'ratio'        => 1.25,
			'private'      => true,
			'added_on'     => 1750000000,
            ],
            $this->client,
		);

		$this->assertSame('abc123', $torrent->hash);
		$this->assertSame('ubuntu-24.04.iso', $torrent->name);
		$this->assertSame(TorrentState::DOWNLOADING, $torrent->state);
		$this->assertSame(0.42, $torrent->progress);
		$this->assertSame(6291456, $torrent->size);
		$this->assertSame('linux', $torrent->category);
		$this->assertSame('iso,release', $torrent->tags);
		$this->assertSame(12, $torrent->num_seeds);
		$this->assertSame(1.25, $torrent->ratio);
		$this->assertTrue($torrent->private);
	}

	public function testComputedPropertiesDeriveFromThePayload(): void
	{
		$torrent = new Torrent(['progress' => 0.4256, 'size' => 6291456], $this->client);

		$this->assertSame(42.56, $torrent->progress_percent);
		$this->assertFalse($torrent->is_complete);
		$this->assertSame('6 MB', $torrent->size_human);
	}

	public function testIsCompleteAtFullProgress(): void
	{
		$this->assertTrue((new Torrent(['progress' => 1.0], $this->client))->is_complete);
	}

	public function testAddTagsUpdatesTheLocalFieldWithoutRefetching(): void
	{
		$this->client()->expects($this->once())
			->method('addTorrentTags')
			->with('abc', ['iso', 'x86']);

		$torrent = new Torrent(['hash' => 'abc', 'tags' => 'iso'], $this->client());

		$torrent->addTags(['iso', 'x86']);

		$this->assertSame('iso,x86', $torrent->tags);
	}

	public function testAddTagsDeduplicatesAgainstExistingTags(): void
	{
		$this->client()->expects($this->once())->method('addTorrentTags');

		$torrent = new Torrent(['hash' => 'abc', 'tags' => 'iso,release'], $this->client());

		$torrent->addTags('iso');

		$this->assertSame('iso,release', $torrent->tags);
	}

	public function testAddTagsAcceptsABareString(): void
	{
		$this->client()->expects($this->once())
			->method('addTorrentTags')
			->with('abc', ['fresh']);

		$torrent = new Torrent(['hash' => 'abc', 'tags' => ''], $this->client());

		$torrent->addTags('fresh');

		$this->assertSame('fresh', $torrent->tags);
	}

	public function testRemoveTagsSubtractsFromTheLocalField(): void
	{
		$this->client()->expects($this->once())
			->method('removeTorrentTags')
			->with('abc', ['iso']);

		$torrent = new Torrent(['hash' => 'abc', 'tags' => 'iso,release,x86'], $this->client());

		$torrent->removeTags('iso');

		$this->assertSame('release,x86', $torrent->tags);
	}

	public function testTagListIgnoresEmptySegmentsFromTrailingCommas(): void
	{
		$this->client()->expects($this->exactly(2))->method('addTorrentTags');

		$torrent = new Torrent(['hash' => 'abc', 'tags' => 'a,,b,'], $this->client());

		$torrent->addTags('c');
		$torrent->addTags('d');

		// The double comma and trailing comma must not become empty tags.
		$this->assertSame('a,b,c,d', $torrent->tags);
	}

	public function testSetTagsOverwritesRatherThanMerges(): void
	{
		$this->client()->expects($this->once())
			->method('setTorrentTags')
			->with('abc', ['x', 'y']);

		$torrent = new Torrent(['hash' => 'abc', 'tags' => 'old'], $this->client());

		$torrent->setTags(['x', 'y']);

		$this->assertSame('x,y', $torrent->tags);
	}

	/**
	 * Relation-invalidating mutators must drop the cached entry, or a caller
	 * would keep seeing the pre-mutation collection.
	 */
	public function testAddTrackersInvalidatesTheCachedTrackersRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentTrackers')
			->willReturnOnConsecutiveCalls(
				[['url' => 'https://old.test/announce', 'status' => 2]],
				[['url' => 'https://new.test/announce', 'status' => 2]],
			);
		$this->client()->expects($this->once())
			->method('addTrackers')
			->with('abc', 'https://new.test/announce');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$this->assertSame('https://old.test/announce', $torrent->trackers[0]->url);

		$torrent->addTrackers('https://new.test/announce');

		$this->assertSame('https://new.test/announce', $torrent->trackers[0]->url, 'The relation must be refetched.');
	}

	public function testEditTrackerInvalidatesTheCachedTrackersRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentTrackers')
			->willReturnOnConsecutiveCalls(
				[['url' => 'https://a.test/announce', 'status' => 2]],
				[['url' => 'https://b.test/announce', 'status' => 2]],
			);
		$this->client()->expects($this->once())
			->method('editTracker')
			->with('abc', 'https://a.test/announce', 'https://b.test/announce');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$torrent->trackers;
		$torrent->editTracker('https://a.test/announce', 'https://b.test/announce');

		$this->assertSame('https://b.test/announce', $torrent->trackers[0]->url);
	}

	public function testRemoveTrackersInvalidatesTheCachedTrackersRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentTrackers')
			->willReturnOnConsecutiveCalls(
				[['url' => 'https://a.test/announce', 'status' => 2]],
				[],
			);
		$this->client()->expects($this->once())->method('removeTrackers');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$torrent->trackers;
		$torrent->removeTrackers('https://a.test/announce');

		$this->assertSame([], $torrent->trackers);
	}

	public function testAddPeersInvalidatesTheCachedPeersRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentPeers')
			->willReturnOnConsecutiveCalls(
				[['ip' => '1.2.3.4', 'port' => 6881]],
				[['ip' => '5.6.7.8', 'port' => 51413]],
			);
		$this->client()->expects($this->once())->method('addPeers');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$this->assertSame('1.2.3.4', $torrent->peers[0]->ip);

		$torrent->addPeers('5.6.7.8');

		$this->assertSame('5.6.7.8', $torrent->peers[0]->ip);
	}

	public function testSetFilePrioInvalidatesTheCachedFilesRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentFiles')
			->willReturnOnConsecutiveCalls(
				[['index' => 0, 'name' => 'a.mkv', 'priority' => 1]],
				[['index' => 0, 'name' => 'a.mkv', 'priority' => 7]],
			);
		$this->client()->expects($this->once())
			->method('setFilePrio')
			->with('abc', '0', 7);

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$this->assertSame(FilePriority::NORMAL, $torrent->files[0]->priority);

		$torrent->setFilePrio('0', 7);

		$this->assertSame(FilePriority::MAXIMUM, $torrent->files[0]->priority);
	}

	public function testRenameFileInvalidatesTheCachedFilesRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentFiles')
			->willReturnOnConsecutiveCalls(
				[['index' => 0, 'name' => 'old.mkv']],
				[['index' => 0, 'name' => 'new.mkv']],
			);
		$this->client()->expects($this->once())
			->method('renameFile')
			->with('abc', 'old.mkv', 'new.mkv');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$torrent->files;
		$torrent->renameFile('old.mkv', 'new.mkv');

		$this->assertSame('new.mkv', $torrent->files[0]->name);
	}

	public function testRenameFolderInvalidatesTheCachedFilesRelation(): void
	{
		$this->client()->expects($this->exactly(2))
			->method('getTorrentFiles')
			->willReturnOnConsecutiveCalls(
				[['index' => 0, 'name' => 'Season 1/ep1.mkv']],
				[['index' => 0, 'name' => 'Season 2/ep1.mkv']],
			);
		$this->client()->expects($this->once())->method('renameFolder');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$torrent->files;
		$torrent->renameFolder('Season 1', 'Season 2');

		$this->assertSame('Season 2/ep1.mkv', $torrent->files[0]->name);
	}

	public function testWebSeedMutatorsInvalidateTheCachedWebseedsRelation(): void
	{
		$cases = [
			'addWebSeeds'    => ['https://a.test/'],
			'editWebSeed'    => ['https://a.test/', 'https://b.test/'],
			'removeWebSeeds' => ['https://a.test/'],
		];

		foreach ($cases as $method => $args) {
			$client = $this->createMock(Client::class);
			$client->expects($this->exactly(2))
				->method('getTorrentWebSeeds')
				->willReturnOnConsecutiveCalls(
					[['url' => 'https://a.test/']],
					[['url' => 'https://b.test/']],
				);
			$client->expects($this->once())->method($method);

			$torrent = new Torrent(['hash' => 'abc'], $client);

			$torrent->webseeds;
			$torrent->$method(...$args);

			$this->assertSame('https://b.test/', $torrent->webseeds[0]->url, $method);
		}
	}

	public function testSetDownloadLimitUpdatesTheLocalField(): void
	{
		$this->client()->expects($this->once())
			->method('setDownloadLimit')
			->with('abc', 1048576);

		$torrent = new Torrent(['hash' => 'abc', 'dl_limit' => 0], $this->client());

		$torrent->setDownloadLimit(1048576);

		$this->assertSame(1048576, $torrent->dl_limit);
	}

	public function testGetDownloadLimitUnwrapsTheHashKeyedResponse(): void
	{
		// The endpoint returns {hash: limit}, so the DTO must index its own hash.
		$this->client()->expects($this->once())
			->method('getDownloadLimit')
			->with('abc')
			->willReturn(['abc' => 524288]);

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$this->assertSame(524288, $torrent->getDownloadLimit());
	}

	public function testSetUploadLimitUpdatesTheLocalField(): void
	{
		$this->client()->expects($this->once())
			->method('setUploadLimit')
			->with('abc', 2097152);

		$torrent = new Torrent(['hash' => 'abc', 'up_limit' => 0], $this->client());

		$torrent->setUploadLimit(2097152);

		$this->assertSame(2097152, $torrent->up_limit);
	}

	public function testGetUploadLimitUnwrapsTheHashKeyedResponse(): void
	{
		$this->client()->expects($this->once())
			->method('getUploadLimit')
			->with('abc')
			->willReturn(['abc' => 1024]);

		$this->assertSame(1024, (new Torrent(['hash' => 'abc'], $this->client()))->getUploadLimit());
	}

	public function testSetShareLimitsUpdatesEveryTrackedFieldAndHydratesTheEnums(): void
	{
		$this->client()->expects($this->once())
			->method('setShareLimits')
			->with('abc', 2.5, 3600, 1800, 'Stop', 'MatchAll');

		$torrent = new Torrent(
            [
			'hash'                        => 'abc',
			'ratio_limit'                 => 0.0,
			'seeding_time_limit'          => 0,
			'inactive_seeding_time_limit' => 0,
            ],
            $this->client(),
		);

		$torrent->setShareLimits(2.5, 3600, 1800, 'Stop', 'MatchAll');

		$this->assertSame(2.5, $torrent->ratio_limit);
		$this->assertSame(3600, $torrent->seeding_time_limit);
		$this->assertSame(1800, $torrent->inactive_seeding_time_limit);
		$this->assertSame(ShareLimitAction::STOP, $torrent->share_limit_action);
		$this->assertSame(ShareLimitsMode::MATCH_ALL, $torrent->share_limits_mode);
	}

	public function testSetShareLimitsDefaultsToDefaultActionAndMode(): void
	{
		$this->client()->expects($this->once())
			->method('setShareLimits')
			->with('abc', 1.0, 60, 30, 'Default', 'Default');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$torrent->setShareLimits(1.0, 60, 30);

		$this->assertSame(ShareLimitAction::DEFAULT, $torrent->share_limit_action);
		$this->assertSame(ShareLimitsMode::DEFAULT, $torrent->share_limits_mode);
	}

	public function testToggleSequentialDownloadFlipsTheLocalFlagBothWays(): void
	{
		$this->client()->expects($this->exactly(2))->method('toggleSequentialDownload');

		$torrent = new Torrent(['hash' => 'abc', 'seq_dl' => false], $this->client());

		$torrent->toggleSequentialDownload();
		$this->assertTrue($torrent->seq_dl);

		$torrent->toggleSequentialDownload();
		$this->assertFalse($torrent->seq_dl);
	}

	public function testToggleFirstLastPiecePrioFlipsTheLocalFlag(): void
	{
		$this->client()->expects($this->once())->method('toggleFirstLastPiecePrio');

		$torrent = new Torrent(['hash' => 'abc', 'f_l_piece_prio' => false], $this->client());

		$torrent->toggleFirstLastPiecePrio();

		$this->assertTrue($torrent->f_l_piece_prio);
	}

	/**
	 * Simple field-setting wrappers: each hits one endpoint and mirrors the
	 * value locally.
	 */
	public function testFieldSettersUpdateLocally(): void
	{
		$cases = [
			['setCategory', 'setTorrentCategory', 'category', 'linux'],
			['setComment', 'setTorrentComment', 'comment', 'hello'],
			['setSavePath', 'setTorrentSavePath', 'save_path', '/data/torrents'],
			['setDownloadPath', 'setTorrentDownloadPath', 'download_path', '/data/incomplete'],
			['setLocation', 'setLocation', 'save_path', '/mnt/other'],
			['rename', 'renameTorrent', 'name', 'renamed.iso'],
			['setAutoManagement', 'setAutoManagement', 'auto_tmm', true],
			['setForceStart', 'setForceStart', 'force_start', true],
			['setSuperSeeding', 'setSuperSeeding', 'super_seeding', true],
		];

		foreach ($cases as [$dtoMethod, $clientMethod, $field, $value]) {
			$client = $this->createMock(Client::class);
			$client->expects($this->once())
				->method($clientMethod)
				->with('abc', $value);

			$torrent = new Torrent(['hash' => 'abc'], $client);

			$torrent->$dtoMethod($value);

			$this->assertSame($value, $torrent->$field, $dtoMethod);
		}
	}

	public function testDownloadableFilesExcludesDoNotDownloadEntries(): void
	{
		$this->client()->expects($this->once())
			->method('getTorrentFiles')
			->willReturn(
                [
				['index' => 0, 'name' => 'keep.mkv', 'priority' => 1],
				['index' => 1, 'name' => 'skip.mkv', 'priority' => 0],
				['index' => 2, 'name' => 'also.mkv', 'priority' => 7],
                ]
			);

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$names = array_map(fn (File $f) => $f->name, $torrent->downloadable_files);

		$this->assertSame(['keep.mkv', 'also.mkv'], $names);
	}

	public function testDeleteForwardsTheDeleteFilesFlag(): void
	{
		$this->client()->expects($this->once())
			->method('delete')
			->with('abc', true);

		(new Torrent(['hash' => 'abc'], $this->client()))->delete(true);
	}

	public function testActionOnlyMethodsJustProxy(): void
	{
		foreach (
            [
			'recheck'                     => 'recheck',
			'stop'                        => 'stop',
			'start'                       => 'start',
			'reannounce'                  => 'reannounce',
			'increasePrio'                => 'increasePrio',
			'decreasePrio'                => 'decreasePrio',
			'topPrio'                     => 'topPrio',
			'bottomPrio'                  => 'bottomPrio',
            ] as $dtoMethod => $clientMethod
        ) {
			$client = $this->createMock(Client::class);
			$client->expects($this->once())
				->method($clientMethod)
				->with('abc');

			(new Torrent(['hash' => 'abc'], $client))->$dtoMethod();
		}
	}

	public function testReturnValuePassThroughs(): void
	{
		$this->client()->method('downloadFile')->willReturn('/dl/abc/0');
		$this->client()->method('exportTorrent')->willReturn('d8:announce4:testee');
		$this->client()->method('getTorrentSslParameters')
			->willReturn(['ssl_certificate' => 'cert']);
		$this->client()->expects($this->once())
			->method('setTorrentSslParameters')
			->with('abc', 'cert', 'key', 'dh');

		$torrent = new Torrent(['hash' => 'abc'], $this->client());

		$this->assertSame('/dl/abc/0', $torrent->downloadFile('0'));
		$this->assertSame('d8:announce4:testee', $torrent->export());
		$this->assertSame(['ssl_certificate' => 'cert'], $torrent->getSslParameters());

		$torrent->setSslParameters('cert', 'key', 'dh');
	}

	public function testDownloadFileAcceptsAFileDto(): void
	{
		$file = new File(['index' => 3, 'name' => 'a.mkv']);

		$this->client()->expects($this->once())
			->method('downloadFile')
			->with('abc', $file);

		(new Torrent(['hash' => 'abc'], $this->client()))->downloadFile($file);
	}

	public function testRelationsHydrateIntoTheirOwnDtos(): void
	{
		$this->client->method('getTorrentTrackers')->willReturn([['url' => 'https://t.test/', 'status' => 2]]);
		$this->client->method('getTorrentPeers')->willReturn([['ip' => '1.1.1.1', 'port' => 1]]);
		$this->client->method('getTorrentWebSeeds')->willReturn([['url' => 'https://w.test/']]);

		$torrent = new Torrent(['hash' => 'abc'], $this->client);

		$this->assertInstanceOf(Tracker::class, $torrent->trackers[0]);
		$this->assertInstanceOf(Peer::class, $torrent->peers[0]);
		$this->assertInstanceOf(WebSeed::class, $torrent->webseeds[0]);
	}

	public function testJsonSerializeOmitsRelationsUntilTheyAreLoaded(): void
	{
		$this->client->method('getTorrentTrackers')->willReturn([]);

		$torrent = new Torrent(['hash' => 'abc'], $this->client);

		$before = $torrent->jsonSerialize();
		$this->assertArrayNotHasKey('trackers', $before);

		$torrent->trackers;

		$after = $torrent->jsonSerialize();
		$this->assertArrayHasKey('trackers', $after);
		$this->assertSame([], $after['trackers']);
	}

	/**
	 * A strict mock for the tests that assert on what the DTO asked the client
	 * to do. $this->client remains the permissive stub for the pure ones.
	 */
	private function client(): Client
	{
		if (!$this->strictClient) {
			$this->strictClient = $this->createMock(Client::class);
		}

		return $this->strictClient;
	}

	private ?Client $strictClient = null;

	protected function tearDown(): void
	{
		$this->strictClient = null;

		parent::tearDown();
	}
}
