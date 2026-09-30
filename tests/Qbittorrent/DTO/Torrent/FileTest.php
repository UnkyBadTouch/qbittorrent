<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Torrent\File;
use Blackout\Qbittorrent\Enum\FilePriority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(File::class)]
final class FileTest extends TestCase
{
	public function testHydratesFromTheTorrentFilesResponse(): void
	{
		$file = new File(
            [
			'index'         => 0,
			'name'          => 'Season 1/ep1.mkv',
			'size'          => 1048576,
			'progress'      => 0.5,
			'priority'      => 1,
			'is_seed'       => false,
			'availability'  => 1.0,
			'piece_range'   => [0, 100],
            ]
		);

		$this->assertSame(0, $file->index);
		$this->assertSame('Season 1/ep1.mkv', $file->name);
		$this->assertSame(1048576, $file->size);
		$this->assertSame(0.5, $file->progress);
		$this->assertSame(FilePriority::NORMAL, $file->priority);
		$this->assertFalse($file->is_seed);
		$this->assertSame(1.0, $file->availability);
		$this->assertSame([0, 100], $file->piece_range);
	}

	public function testProgressPercentScalesToOneHundred(): void
	{
		$this->assertSame(50.0, (new File(['progress' => 0.5]))->progress_percent);
		$this->assertSame(33.33, (new File(['progress' => 0.3333]))->progress_percent);
		$this->assertSame(0.0, (new File(['progress' => 0]))->progress_percent);
	}

	#[DataProvider('completeProvider')]
	public function testIsCompleteTracksFullProgress(float $progress, bool $expected): void
	{
		$this->assertSame($expected, (new File(['progress' => $progress]))->is_complete);
	}

	public static function completeProvider(): array
	{
		return [
			'zero'     => [0.0, false],
			'partial'  => [0.999, false],
			'full'     => [1.0, true],
			'overfull' => [1.5, true],
		];
	}

	public function testSizeHumanUsesFilesizeFormatting(): void
	{
		$this->assertSame('1 MB', (new File(['size' => 1048576]))->size_human);
		$this->assertSame('512 B', (new File(['size' => 512]))->size_human);
	}

	public function testBasenameAndDirnameSplitThePath(): void
	{
		$file = new File(['name' => 'Season 1/extras/ep1.mkv']);

		$this->assertSame('ep1.mkv', $file->basename);
		$this->assertSame('Season 1/extras', $file->dirname);
	}

	public function testBasenameAndDirnameOnAFlatFilename(): void
	{
		$file = new File(['name' => 'readme.txt']);

		$this->assertSame('readme.txt', $file->basename);
		$this->assertSame('.', $file->dirname);
	}

	/**
	 * Each path segment is encoded separately, so a filename containing a
	 * space or a "#" survives the round trip through a URL.
	 */
	#[DataProvider('urlPathProvider')]
	public function testUrlPathEncodesEachSegment(string $name, string $expected): void
	{
		$this->assertSame($expected, (new File(['name' => $name]))->url_path);
	}

	public static function urlPathProvider(): array
	{
		return [
			'plain'              => ['a.mkv', 'a.mkv'],
			'space'              => ['my file.mkv', 'my%20file.mkv'],
			'hash'               => ['a#b.mkv', 'a%23b.mkv'],
			'plus'               => ['a+b.mkv', 'a%2Bb.mkv'],
			'nested'             => ['dir/a.mkv', 'dir/a.mkv'],
			'nested with space'  => ['my dir/a b.mkv', 'my%20dir/a%20b.mkv'],
			'question mark'      => ['a?b.mkv', 'a%3Fb.mkv'],
			'ampersand'          => ['a&b.mkv', 'a%26b.mkv'],
			'unicode'            => ['Ünï.mkv', '%C3%9Cn%C3%AF.mkv'],
			'percent'            => ['100%.mkv', '100%25.mkv'],
		];
	}

	public function testPriorityHydratesAsAnEnumNotAnInt(): void
	{
		foreach (
            [
			0 => FilePriority::DO_NOT_DOWNLOAD,
			1 => FilePriority::NORMAL,
			6 => FilePriority::HIGH,
			7 => FilePriority::MAXIMUM,
            ] as $value => $expected
        ) {
			$this->assertSame($expected, (new File(['priority' => $value]))->priority);
		}
	}

	public function testUnknownPriorityValueIsRejected(): void
	{
		// The API's FilePriority enum is closed; an unrecognised value means
		// the shape changed and from() must not silently invent a case.
		$this->expectException(\ValueError::class);

		new File(['priority' => 3]);
	}

	public function testJsonSerializeOmitsTheComputedProperties(): void
	{
		$json = (new File(['index' => 0, 'name' => 'a.mkv', 'size' => 1, 'progress' => 0.5]))->jsonSerialize();

		$this->assertArrayNotHasKey('progress_percent', $json);
		$this->assertArrayNotHasKey('is_complete', $json);
		$this->assertArrayNotHasKey('size_human', $json);
		$this->assertArrayNotHasKey('basename', $json);
		$this->assertArrayNotHasKey('dirname', $json);
		$this->assertArrayNotHasKey('url_path', $json);
		$this->assertSame(['index' => 0, 'name' => 'a.mkv', 'size' => 1, 'progress' => 0.5], $json);
	}
}
