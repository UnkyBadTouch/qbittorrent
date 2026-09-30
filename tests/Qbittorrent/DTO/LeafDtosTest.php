<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\DTO\Torrent\Peer;
use Blackout\Qbittorrent\DTO\Torrent\Piece;
use Blackout\Qbittorrent\DTO\Torrent\Properties;
use Blackout\Qbittorrent\DTO\Torrent\Tracker;
use Blackout\Qbittorrent\DTO\Torrent\WebSeed;
use Blackout\Qbittorrent\Enum\PieceState;
use Blackout\Qbittorrent\Enum\TrackerStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The remaining DTOs. Most carry no behaviour beyond hydration, so the value
 * here is in pinning the field names and types the API actually sends — a
 * property declared under a slightly wrong name silently never populates.
 */
#[CoversClass(Tracker::class)]
#[CoversClass(Peer::class)]
#[CoversClass(Piece::class)]
#[CoversClass(WebSeed::class)]
#[CoversClass(Properties::class)]
final class LeafDtosTest extends TestCase
{
	public function testTrackerHydratesTheSingleTrackerFields(): void
	{
		$tracker = new Tracker(
            [
			'url'           => '** [DHT] **',
			'status'        => 0,
			'tier'          => 0,
			'num_peers'     => 0,
			'num_seeds'     => 0,
			'num_leeches'   => 0,
			'num_downloaded' => 0,
			'msg'           => '',
            ]
		);

		$this->assertSame('** [DHT] **', $tracker->url);
		$this->assertSame(TrackerStatus::DISABLED, $tracker->status);
		$this->assertSame(0, $tracker->tier);
		$this->assertSame('', $tracker->msg);
	}

	/**
	 * Multi-tracker (v2) entries carry extra per-endpoint fields that a
	 * single-tracker row does not have.
	 */
	public function testTrackerHydratesTheMultiTrackerFields(): void
	{
		$tracker = new Tracker(
            [
			'url'            => 'https://a.test/announce',
			'status'         => 2,
			'name'           => 'Tracker A',
			'updating'       => false,
			'bt_version'     => 2,
			'next_announce'  => 1750000300,
			'min_announce'   => 1800,
			'endpoints'      => [['ip' => '1.2.3.4', 'status' => 2]],
            ]
		);

		$this->assertSame('Tracker A', $tracker->name);
		$this->assertFalse($tracker->updating);
		$this->assertSame(2, $tracker->bt_version);
		$this->assertSame(1800, $tracker->min_announce);
		$this->assertCount(1, $tracker->endpoints);
	}

	public function testPeerHydratesItsFields(): void
	{
		$peer = new Peer(
            [
			'ip'            => '1.2.3.4',
			'port'          => 51413,
			'client'        => 'qBt 4.6.5',
			'peer_id_client' => 'qBittorrent',
			'country'       => 'Germany',
			'country_code'  => 'de',
			'progress'      => 0.75,
			'dl_speed'      => 1024,
			'up_speed'      => 2048,
			'downloaded'    => 5000,
			'uploaded'      => 10000,
			'connection'    => 'BT',
			'flags'         => 'D X',
			'flags_desc'    => 'D = interested, X = seeding',
			'relevance'     => 1.0,
			'files'         => 'ubuntu.iso',
			'contribution'  => 0.5,
			'host_name'     => 'peer.example',
            ]
		);

		$this->assertSame('1.2.3.4', $peer->ip);
		$this->assertSame(51413, $peer->port);
		$this->assertSame('de', $peer->country_code);
		$this->assertSame(0.75, $peer->progress);
		$this->assertSame(2048, $peer->up_speed);
		$this->assertSame(0.5, $peer->contribution);
		$this->assertSame('peer.example', $peer->host_name);
	}

	public function testPieceHydratesItsStateAsAnEnum(): void
	{
		$piece = new Piece(['index' => 0, 'state' => 0]);

		$this->assertSame(0, $piece->index);
		$this->assertSame(PieceState::NOT_DOWNLOADED, $piece->state);
		$this->assertFalse($piece->is_downloaded);
		$this->assertFalse($piece->is_downloading);
	}

	public function testPieceFlagsFollowItsState(): void
	{
		$downloading = new Piece(['index' => 1, 'state' => 1]);
		$this->assertTrue($downloading->is_downloading);
		$this->assertFalse($downloading->is_downloaded);

		$downloaded = new Piece(['index' => 2, 'state' => 2]);
		$this->assertTrue($downloaded->is_downloaded);
		$this->assertFalse($downloaded->is_downloading);
	}

	public function testWebSeedCarriesOnlyItsUrl(): void
	{
		$seed = new WebSeed(['url' => 'https://w.test/files/']);

		$this->assertSame('https://w.test/files/', $seed->url);
		$this->assertSame(['url' => 'https://w.test/files/'], $seed->jsonSerialize());
	}

	/**
	 * Torrent and Properties come from different endpoints with different
	 * field sets — they are deliberately not merged, so each keeps its own.
	 */
	public function testPropertiesUsesThePropertiesEndpointFieldNames(): void
	{
		$properties = new Properties(
            [
			'hash'            => 'abc',
			'name'            => 'ubuntu.iso',
			'save_path'       => '/downloads',
			'piece_size'      => 262144,
			'pieces_num'      => 24,
			'pieces_have'     => 24,
			'total_size'      => 6291456,
			'total_wasted'    => 0,
			'progress'        => 1.0,
			'ratio'           => 1.5,
			'share_ratio'     => 1.5,
			'total_downloaded' => 6291456,
			'total_uploaded'  => 9437184,
			'dl_speed'        => 0,
			'dl_speed_avg'    => 1024,
			'up_speed'        => 512,
			'up_speed_avg'    => 2048,
			'seeds'           => 5,
			'seeds_total'     => 50,
			'peers'           => 2,
			'peers_total'     => 20,
			'nb_connections'  => 7,
			'time_elapsed'    => 86400,
			'seeding_time'    => 3600,
			'eta'             => 0,
            ]
		);

		$this->assertSame('abc', $properties->hash);
		$this->assertSame(6291456, $properties->total_size);
		$this->assertSame(1.5, $properties->share_ratio);
		$this->assertSame(1024, $properties->dl_speed_avg);
		$this->assertSame(50, $properties->seeds_total);
		$this->assertSame(86400, $properties->time_elapsed);
	}

	/**
	 * Properties carries both is_private and private; they are separate API
	 * fields and must not be collapsed into one.
	 */
	public function testPropertiesKeepsBothPrivateFields(): void
	{
		$properties = new Properties(['is_private' => true, 'private' => false]);

		$this->assertTrue($properties->is_private);
		$this->assertFalse($properties->private);
	}

	/**
	 * The two seeding-time limits are distinct API keys that mirror
	 * seeding_time_limit and max_seeding_time respectively.
	 */
	public function testPropertiesKeepsTheDistinctSeedingTimeLimits(): void
	{
		$properties = new Properties([
			'seeding_time'               => 100,
			'time_elapsed'               => 200,
		]);

		$this->assertSame(100, $properties->seeding_time);
		$this->assertSame(200, $properties->time_elapsed);
	}

	/**
	 * Torrent has no share_limits_mode field, because the API never populates
	 * one — a property declared for it would never receive a value.
	 */
	public function testPropertiesDoesNotDeclareShareLimitsMode(): void
	{
		$this->assertFalse(property_exists(Properties::class, 'share_limits_mode'));
	}
}
