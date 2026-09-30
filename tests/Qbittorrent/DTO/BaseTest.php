<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Attribute\Relation;
use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Base;
use Blackout\Qbittorrent\DTO\Torrent;
use Blackout\Qbittorrent\DTO\Torrent\File;
use Blackout\Qbittorrent\Enum\PieceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Base::class)]
#[CoversClass(Relation::class)]
final class BaseTest extends TestCase
{
	public function testHydratesMatchingPublicProperties(): void
	{
		$dto = $this->dto(['name' => 'Ubuntu', 'size' => 1024, 'ratio' => 1.5]);

		$this->assertSame('Ubuntu', $dto->name);
		$this->assertSame(1024, $dto->size);
		$this->assertSame(1.5, $dto->ratio);
	}

	public function testCastsScalarTypesFromTheApiPayload(): void
	{
		// The API is loosely typed: progress arrives as a JSON number, eta can
		// arrive as a string on some builds. Each property must land as its
		// declared type regardless of what the wire gave us.
		$dto = $this->dto(
            [
			'name'     => 123,
			'size'     => '4096',
			'ratio'    => '2',
			'private'  => 1,
			'priority' => 0,
            ]
		);

		$this->assertSame('123', $dto->name);
		$this->assertSame(4096, $dto->size);
		$this->assertSame(2.0, $dto->ratio);
		$this->assertTrue($dto->private);
		$this->assertSame(0, $dto->priority);
	}

	public function testHydratesBackedEnumsFromTheirScalarValue(): void
	{
		$dto = $this->dto(['state' => 'downloading']);

		$this->assertSame(\Blackout\Qbittorrent\Enum\TorrentState::DOWNLOADING, $dto->state);
	}

	public function testHydratesNestedBaseSubclasses(): void
	{
		$properties = new Torrent\Properties(['save_path' => '/downloads', 'seeding_time' => 900]);

		$this->assertInstanceOf(Torrent\Properties::class, $properties);
		$this->assertSame('/downloads', $properties->save_path);
		$this->assertSame(900, $properties->seeding_time);
	}

	public function testHydrationSkipsKeysWithNoMatchingProperty(): void
	{
		// The API gains fields over time and a property typed with a slightly
		// wrong name never populates. That must stay silent, not fatal.
		$dto = $this->dto(['name' => 'Ubuntu', 'totally_unknown_field' => 'ignored']);

		$this->assertSame('Ubuntu', $dto->name);
		$this->assertFalse(property_exists($dto, 'totally_unknown_field'));
	}

	public function testHydrationLeavesAbsentPropertiesUninitialised(): void
	{
		$dto = $this->dto(['name' => 'Ubuntu']);

		$this->assertTrue((new \ReflectionProperty($dto, 'size'))->isInitialized($dto) === false);
	}

	public function testHydrationIgnoresNonPublicProperties(): void
	{
		$dto = $this->dto(['_relations' => ['files' => 'nope']]);

		$this->assertSame([], $dto->jsonSerialize());
	}

	public function testHydrationSkipsLazyRelationProperties(): void
	{
		// A relation is fetched on first access, so a payload key of the same
		// name must not populate it. Hooked properties have no backing store,
		// so the proof is that the payload's files key was ignored entirely:
		// the client is never asked for it during construction.
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('getTorrentFiles');

		$torrent = new Torrent(['hash' => 'abc', 'files' => [['name' => 'a.torrent']]], $client);

		$this->assertArrayNotHasKey('files', $torrent->jsonSerialize());
		$this->assertSame([], $this->relationsCache($torrent));
	}

	public function testNullIsPreservedForNullableProperties(): void
	{
		$dto = $this->dto(['private' => null]);

		$this->assertNull($dto->private);
	}

	public function testNullIntoANonNullablePropertyIsATypeError(): void
	{
		// castProperty() short-circuits null before the type match, so a null
		// for a non-nullable property reaches the property write and fatals.
		// That is the current contract: nullable fields are the ones the API
		// is allowed to omit a value for.
		$this->expectException(\TypeError::class);
		$this->expectExceptionMessage('Cannot assign null to property');

		$this->dto(['name' => null]);
	}

	public function testJsonSerializeReturnsOnlyDeclaredPublicScalarFields(): void
	{
		$json = (new Torrent(['hash' => 'abc', 'name' => 'Ubuntu', 'size' => 1024]))->jsonSerialize();

		$this->assertSame(['hash' => 'abc', 'name' => 'Ubuntu', 'size' => 1024], $json);
	}

	public function testJsonSerializeExcludesInheritedBaseProperties(): void
	{
		// $qbittorrent and $_relations live on Base; a subclass's payload must
		// not leak the client reference into serialised output.
		$json = (new Torrent(['hash' => 'abc']))->jsonSerialize();

		$this->assertArrayNotHasKey('qbittorrent', $json);
		$this->assertArrayNotHasKey('_relations', $json);
	}

	public function testJsonSerializeSkipsUninitialisedProperties(): void
	{
		$json = (new Torrent(['hash' => 'abc']))->jsonSerialize();

		$this->assertArrayNotHasKey('name', $json, 'name was never set, so it must be absent.');
	}

	public function testJsonSerializeExcludesUnloadedRelations(): void
	{
		$json = (new Torrent(['hash' => 'abc']))->jsonSerialize();

		$this->assertArrayNotHasKey('files', $json);
		$this->assertArrayNotHasKey('trackers', $json);
	}

	public function testJsonSerializeIncludesLoadedRelations(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getTorrentFiles')
			->willReturn([
				new File(['index' => 0, 'name' => 'a.mkv']),
			]);

		$torrent = new Torrent(['hash' => 'abc'], $client);

		// Force the lazy load, then check the relation reaches the output.
		$this->assertCount(1, $torrent->files);

		$json = $torrent->jsonSerialize();

		$this->assertArrayHasKey('files', $json);
		$this->assertCount(1, $json['files']);
		$this->assertSame('a.mkv', $json['files'][0]->name);
	}

	public function testJsonEncodeRoundTripsThroughTheDtosOwnSerializer(): void
	{
		$torrent = new Torrent(['hash' => 'abc', 'name' => 'Ünïcödé']);

		$this->assertSame('"Ünïcödé"', json_encode($torrent->name, JSON_UNESCAPED_UNICODE));
		$this->assertSame('abc', json_decode(json_encode($torrent), true)['hash']);
	}

	public function testDebugInfoMirrorsJsonSerialize(): void
	{
		$torrent = new Torrent(['hash' => 'abc', 'size' => 5]);

		$this->assertSame($torrent->jsonSerialize(), $torrent->__debugInfo());
	}

	public function testRelationLoadsThroughTheClientAndCachesTheResult(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getTorrentFiles')
			->with('abc')
			->willReturn([
				['index' => 0, 'name' => 'one.mkv'],
				['index' => 1, 'name' => 'two.mkv'],
			]);

		$torrent = new Torrent(['hash' => 'abc'], $client);

		$first = $torrent->files;
		$second = $torrent->files;

		$this->assertCount(2, $first);
		$this->assertInstanceOf(File::class, $first[0]);
		$this->assertSame('one.mkv', $first[0]->name);
		$this->assertSame($first, $second, 'A second access must hit the cache, not the client.');
	}

	public function testRelationAcceptsAlreadyHydratedDtosFromTheClient(): void
	{
		// getTorrentFiles() returns File instances directly; re-wrapping one in
		// new File($dto) would throw, so relation() must pass it through.
		$file = new File(['index' => 0, 'name' => 'already.mkv']);
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getTorrentFiles')
			->willReturn([$file]);

		$torrent = new Torrent(['hash' => 'abc'], $client);

		$this->assertSame($file, $torrent->files[0]);
	}

	public function testRelationPassesTheClientDownToNestedDtos(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getTorrentFiles')
			->willReturn([['index' => 0, 'name' => 'a.mkv']]);

		$file = (new Torrent(['hash' => 'abc'], $client))->files[0];

		$this->assertInstanceOf(File::class, $file);
		$this->assertInstanceOf(Client::class, (new \ReflectionProperty($file, 'qbittorrent'))->getValue($file));
	}

	public function testRelationWithoutTheAttributeThrows(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method($this->anything());

		// A hooked property with no #[Relation] on it. Reading it must fail
		// loudly rather than silently returning an empty collection.
		$dto = new class ([], $client) extends Base
		{
			public array $files { get => $this->relation(__PROPERTY__); }
		};

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Missing relation attribute [files]');

		$dto->files;
	}

	public function testRelationOnASubclassWithTheAttributeWorks(): void
	{
		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('getTorrentFiles')
			->with('abc')
			->willReturn([]);

		$dto = new class (['hash' => 'abc'], $client) extends Base
		{
			public string $hash;

			#[Relation('getTorrentFiles', File::class)]
			public array $files { get => $this->relation(__PROPERTY__); }
		};

		$this->assertSame([], $dto->files);
	}

	public function testEmptyPayloadProducesADtoWithNothingSet(): void
	{
		$dto = new Torrent();

		$this->assertSame([], $dto->jsonSerialize());
	}

	public function testConstructingWithoutAClientIsAllowedForReadOnlyDtos(): void
	{
		$torrent = new Torrent(['hash' => 'abc']);

		$this->assertNull((new \ReflectionProperty($torrent, 'qbittorrent'))->getValue($torrent));
		$this->assertSame('abc', $torrent->hash);
	}

	public function testEnumCastingRejectsAMismatchedBackingType(): void
	{
		// Torrent::$state is a string-backed enum. The API sending an int here
		// is a shape mismatch, and from() must reject it loudly rather than
		// coerce 0 into a state.
		$this->expectException(\TypeError::class);
		$this->expectExceptionMessage('must be of type string, int given');

		$this->dto(['state' => 0]);
	}

	public function testPieceStateEnumHydratesOnANestedDto(): void
	{
		$piece = new Torrent\Piece(['index' => 4, 'state' => 2]);

		$this->assertSame(PieceState::DOWNLOADED, $piece->state);
		$this->assertTrue($piece->is_downloaded);
	}

	/**
	 * A minimal concrete Base for testing hydration in isolation from any
	 * particular DTO's field set.
	 */
	private function dto(array $data): object
	{
		return new class ($data) extends Base
		{
			public string $name;
			public int $size;
			public float $ratio;
			public ?bool $private;
			public int $priority;
			public \Blackout\Qbittorrent\Enum\TorrentState $state;
		};
	}

	/** The DTO's lazy-relation cache, which is protected on Base. */
	private function relationsCache(Base $dto): array
	{
		return (new \ReflectionProperty($dto, '_relations'))->getValue($dto);
	}
}
