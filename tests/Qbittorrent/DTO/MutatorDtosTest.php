<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Search\Plugin;
use Blackout\Qbittorrent\DTO\Search\Result;
use Blackout\Qbittorrent\DTO\Search\Status;
use Blackout\Qbittorrent\DTO\Transfer;
use Blackout\Qbittorrent\Enum\SearchStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transfer::class)]
#[CoversClass(Status::class)]
#[CoversClass(Plugin::class)]
#[CoversClass(Result::class)]
final class MutatorDtosTest extends TestCase
{
	public function testTransferHydratesItsFields(): void
	{
		$transfer = new Transfer(
            [
			'connection_status'      => 'connected',
			'dht_nodes'              => 320,
			'dl_info_speed'          => 1048576,
			'dl_rate_limit'          => 0,
			'up_info_speed'          => 524288,
			'up_rate_limit'          => 1048576,
            ]
		);

		$this->assertSame('connected', $transfer->connection_status);
		$this->assertSame(320, $transfer->dht_nodes);
		$this->assertSame(1048576, $transfer->dl_info_speed);
		$this->assertSame(524288, $transfer->up_info_speed);
	}

	public function testTransferSpeedLimitsModeDelegatesToTheClient(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getSpeedLimitsMode')
			->willReturn(true);

		$this->assertTrue((new Transfer([], $client))->speedLimitsMode());
	}

	public function testTransferToggleSpeedLimitsModeJustProxies(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())->method('toggleSpeedLimitsMode');

		(new Transfer([], $client))->toggleSpeedLimitsMode();
	}

	public function testTransferSetDownloadLimitUpdatesTheLocalField(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('setGlobalDownloadLimit')
			->with(1048576);

		$transfer = new Transfer(['dl_rate_limit' => 0], $client);

		$transfer->setDownloadLimit(1048576);

		$this->assertSame(1048576, $transfer->dl_rate_limit);
	}

	public function testTransferSetUploadLimitUpdatesTheLocalField(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('setGlobalUploadLimit')
			->with(2097152);

		$transfer = new Transfer(['up_rate_limit' => 0], $client);

		$transfer->setUploadLimit(2097152);

		$this->assertSame(2097152, $transfer->up_rate_limit);
	}

	public function testTransferBanPeersForwardsTheList(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('banPeers')
			->with(['1.2.3.4', '5.6.7.8']);

		(new Transfer([], $client))->banPeers(['1.2.3.4', '5.6.7.8']);
	}

	public function testSearchStatusStopFlipsTheLocalStatusWithoutRefetching(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('stopSearch')
			->with(7);

		$status = new Status(['id' => 7, 'status' => 'Running', 'total' => 10], $client);

		$status->stop();

		$this->assertSame(SearchStatus::STOPPED, $status->status);
	}

	public function testSearchStatusDeleteForwardsTheId(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('deleteSearch')
			->with(7);

		(new Status(['id' => 7], $client))->delete();
	}

	public function testSearchStatusResultsPassesLimitAndOffsetThrough(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getSearchResults')
			->with(7, 10, 20)
			->willReturn(['results' => [], 'status' => SearchStatus::RUNNING, 'total' => 0]);

		$status = new Status(['id' => 7], $client);

		$results = $status->results(10, 20);

		$this->assertSame(SearchStatus::RUNNING, $results['status']);
	}

	public function testSearchStatusResultsDefaultsToNoPaging(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getSearchResults')
			->with(7, null, null)
			->willReturn([]);

		$this->assertSame([], (new Status(['id' => 7], $client))->results());
	}

	public function testPluginEnableUpdatesTheLocalFlag(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('enableSearchPlugin')
			->with('plugin', true);

		$plugin = new Plugin(['name' => 'plugin', 'enabled' => false], $client);

		$plugin->enable();

		$this->assertTrue($plugin->enabled);
	}

	public function testPluginEnableCanAlsoDisable(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('enableSearchPlugin')
			->with('plugin', false);

		$plugin = new Plugin(['name' => 'plugin', 'enabled' => true], $client);

		$plugin->enable(false);

		$this->assertFalse($plugin->enabled);
	}

	public function testPluginUninstallForwardsTheName(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('uninstallSearchPlugin')
			->with('plugin');

		(new Plugin(['name' => 'plugin'], $client))->uninstall();
	}

	public function testPluginHydratesItsSupportedCategories(): void
	{
		$plugin = new Plugin(
            [
			'name'                => 'plugin',
			'fullName'            => 'Example Plugin',
			'enabled'             => true,
			'supportedCategories' => [
				['id' => 'all', 'name' => 'All categories'],
				['id' => 'movies', 'name' => 'Movies'],
			],
            ]
		);

		$this->assertCount(2, $plugin->supportedCategories);
		$this->assertSame('movies', $plugin->supportedCategories[1]['id']);
	}

	public function testSearchResultDownloadForwardsUrlAndEngine(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('downloadSearchTorrent')
			->with('https://a.test/ubuntu.iso', 'engineName');

		$result = new Result(
            [
			'fileName'    => 'ubuntu.iso',
			'fileUrl'     => 'https://a.test/ubuntu.iso',
			'fileSize'    => 6291456,
			'nbSeeders'   => 5,
			'nbLeechers'  => 2,
			'engineName'  => 'engineName',
            ],
            $client,
		);

		$result->download();

		$this->assertSame(6291456, $result->fileSize);
		$this->assertSame(5, $result->nbSeeders);
	}
}
