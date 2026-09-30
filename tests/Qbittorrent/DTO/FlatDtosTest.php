<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\DTO\BuildInfo;
use Blackout\Qbittorrent\DTO\Log\Message;
use Blackout\Qbittorrent\DTO\Log\Peer;
use Blackout\Qbittorrent\Enum\LogMessageType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildInfo::class)]
#[CoversClass(Message::class)]
#[CoversClass(Peer::class)]
final class FlatDtosTest extends TestCase
{
	public function testBuildInfoHydratesEveryField(): void
	{
		$info = new BuildInfo(
            [
			'bitness'    => 64,
			'boost'      => '1.86.0',
			'libtorrent' => '2.0.10',
			'openssl'    => '3.3.1',
			'platform'   => 'Linux',
			'qt'         => '6.7.2',
			'zlib'       => '1.3.1',
            ]
		);

		$this->assertSame(64, $info->bitness);
		$this->assertSame('1.86.0', $info->boost);
		$this->assertSame('2.0.10', $info->libtorrent);
		$this->assertSame('3.3.1', $info->openssl);
		$this->assertSame('Linux', $info->platform);
		$this->assertSame('6.7.2', $info->qt);
		$this->assertSame('1.3.1', $info->zlib);
	}

	public function testLogMessageHydratesItsTypeAsAnEnum(): void
	{
		$message = new Message(
            [
			'id'        => 42,
			'message'   => 'Torrent added',
			'timestamp' => 1750000000,
			'type'      => 4,
            ]
		);

		$this->assertSame(42, $message->id);
		$this->assertSame('Torrent added', $message->message);
		$this->assertSame(1750000000, $message->timestamp);
		$this->assertSame(LogMessageType::WARNING, $message->type);
		$this->assertSame('Warning', $message->type->label());
	}

	/**
	 * The log type is a bitmask, not an enumeration: qBittorrent can send a
	 * value outside the four defined cases, and from() must reject it rather
	 * than pick the nearest case.
	 */
	public function testLogMessageRejectsAnUnknownType(): void
	{
		$this->expectException(\ValueError::class);

		new Message(['id' => 1, 'type' => 16]);
	}

	public function testLogPeerHydratesItsFields(): void
	{
		$peer = new Peer(
            [
			'id'        => 7,
			'ip'        => '1.2.3.4',
			'timestamp' => 1750000000,
			'blocked'   => true,
			'reason'    => 'Peer banned',
            ]
		);

		$this->assertSame(7, $peer->id);
		$this->assertSame('1.2.3.4', $peer->ip);
		$this->assertTrue($peer->blocked);
		$this->assertSame('Peer banned', $peer->reason);
	}

	/**
	 * The two log Peer and Torrent\Peer classes are distinct types for
	 * distinct endpoints; conflating them would let a peer-log row be read
	 * with the wrong field names.
	 */
	public function testTheTwoPeerDtosAreDistinctTypes(): void
	{
		$this->assertNotSame(
			Peer::class,
			\Blackout\Qbittorrent\DTO\Torrent\Peer::class,
		);
		$this->assertFalse(property_exists(Peer::class, 'client'));
		$this->assertTrue(property_exists(\Blackout\Qbittorrent\DTO\Torrent\Peer::class, 'client'));
	}
}
