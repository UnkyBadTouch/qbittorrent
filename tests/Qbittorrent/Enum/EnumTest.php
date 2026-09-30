<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\Enum;

use Blackout\Qbittorrent\Enum\FilePriority;
use Blackout\Qbittorrent\Enum\LogMessageType;
use Blackout\Qbittorrent\Enum\PieceState;
use Blackout\Qbittorrent\Enum\SearchStatus;
use Blackout\Qbittorrent\Enum\ShareLimitAction;
use Blackout\Qbittorrent\Enum\ShareLimitsMode;
use Blackout\Qbittorrent\Enum\TorrentState;
use Blackout\Qbittorrent\Enum\TrackerStatus;
use Blackout\Qbittorrent\Trait\HasLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnitEnum;

/**
 * Every enum in the library is a backed enum whose backing values are part of
 * the wire contract with qBittorrent, so the values are pinned here in one
 * place. A renumbering would silently change what the client sends.
 */
#[CoversClass(FilePriority::class)]
#[CoversClass(LogMessageType::class)]
#[CoversClass(PieceState::class)]
#[CoversClass(SearchStatus::class)]
#[CoversClass(ShareLimitAction::class)]
#[CoversClass(ShareLimitsMode::class)]
#[CoversClass(TorrentState::class)]
#[CoversClass(TrackerStatus::class)]
#[CoversClass(HasLabel::class)]
final class EnumTest extends TestCase
{
	/**
	 * [enum class, [case name => backing value], is int-backed].
	 *
	 * @return array<string, array{0: class-string<UnitEnum>, 1: array<string, int|string>, 2: bool}>
	 */
	public static function enumProvider(): array
	{
		return [
			'FilePriority' => [
				FilePriority::class,
				[
					'DO_NOT_DOWNLOAD' => 0,
					'NORMAL'          => 1,
					'HIGH'            => 6,
					'MAXIMUM'         => 7,
				],
				true,
			],
			'LogMessageType' => [
				LogMessageType::class,
				[
					'NORMAL'   => 1,
					'INFO'     => 2,
					'WARNING'  => 4,
					'CRITICAL' => 8,
				],
				true,
			],
			'PieceState' => [
				PieceState::class,
				['NOT_DOWNLOADED' => 0, 'DOWNLOADING' => 1, 'DOWNLOADED' => 2],
				true,
			],
			'TrackerStatus' => [
				TrackerStatus::class,
				[
					'DISABLED'      => 0,
					'NOT_CONTACTED' => 1,
					'WORKING'       => 2,
					'NOT_WORKING'   => 4,
					'TRACKER_ERROR' => 5,
					'UNREACHABLE'   => 6,
				],
				true,
			],
			'SearchStatus' => [
				SearchStatus::class,
				['RUNNING' => 'Running', 'STOPPED' => 'Stopped'],
				false,
			],
			'ShareLimitAction' => [
				ShareLimitAction::class,
				[
					'DEFAULT'              => 'Default',
					'STOP'                 => 'Stop',
					'REMOVE'               => 'Remove',
					'REMOVE_WITH_CONTENT'  => 'RemoveWithContent',
					'ENABLE_SUPER_SEEDING' => 'EnableSuperSeeding',
				],
				false,
			],
			'ShareLimitsMode' => [
				ShareLimitsMode::class,
				['DEFAULT' => 'Default', 'MATCH_ANY' => 'MatchAny', 'MATCH_ALL' => 'MatchAll'],
				false,
			],
			'TorrentState' => [
				TorrentState::class,
				[
					'ERROR'                => 'error',
					'MISSING_FILES'        => 'missingFiles',
					'DOWNLOADING'          => 'downloading',
					'UPLOADING'            => 'uploading',
					'STOPPED_DL'           => 'stoppedDL',
					'STOPPED_UP'           => 'stoppedUP',
					'QUEUED_DL'            => 'queuedDL',
					'QUEUED_UP'            => 'queuedUP',
					'STALLED_DL'           => 'stalledDL',
					'STALLED_UP'           => 'stalledUP',
					'CHECKING_DL'          => 'checkingDL',
					'CHECKING_UP'          => 'checkingUP',
					'CHECKING_RESUME_DATA' => 'checkingResumeData',
					'FORCED_DL'            => 'forcedDL',
					'FORCED_UP'            => 'forcedUP',
					'META_DL'              => 'metaDL',
					'FORCED_META_DL'       => 'forcedMetaDL',
					'MOVING'               => 'moving',
					'UNKNOWN'              => 'unknown',
				],
				false,
			],
		];
	}

	/**
	 * @param class-string<UnitEnum> $enum
	 * @param array<string, int|string> $expected
	 */
	#[DataProvider('enumProvider')]
	public function testBackingValuesMatchTheQbittorrentApi(string $enum, array $expected, bool $isInt): void
	{
		$actual = [];

		foreach ($enum::cases() as $case) {
			$actual[$case->name] = $case->value;
		}

		$this->assertSame($expected, $actual);
		$this->assertSame(
			$isInt,
			is_int($expected[array_key_first($expected)]),
			$enum . ' backing type must not change.',
		);
	}

	/**
	 * @param class-string<UnitEnum> $enum
	 */
	#[DataProvider('enumProvider')]
	public function testFromRoundTripsEveryCase(string $enum, array $expected, bool $isInt): void
	{
		unset($isInt);

		foreach ($expected as $name => $value) {
			$case = $enum::from($value);

			$this->assertSame($name, $case->name);
			$this->assertSame($value, $case->value);
			$this->assertSame($case, $enum::from($value), 'from() is deterministic.');
		}
	}

	/**
	 * @param class-string<UnitEnum> $enum
	 * @param array<string, int|string> $expected
	 */
	#[DataProvider('enumProvider')]
	public function testTryFromReturnsNullForUnknownValues(string $enum, array $expected, bool $isInt): void
	{
		$unknown = is_int($expected[array_key_first($expected)]) ? 9999 : 'definitely-not-a-value';

		$this->assertNull($enum::tryFrom($unknown));
	}

	/**
	 * @param class-string<UnitEnum> $enum
	 */
	#[DataProvider('enumProvider')]
	public function testLabelHumanisesTheCaseName(string $enum, array $expected, bool $isInt): void
	{
		unset($expected, $isInt);

		foreach ($enum::cases() as $case) {
			$this->assertSame(
				ucwords(strtolower(str_replace('_', ' ', $case->name))),
				$case->label(),
				$enum . '::' . $case->name,
			);
		}
	}

	public function testFilePriorityIsDoNotDownload(): void
	{
		$this->assertTrue(FilePriority::DO_NOT_DOWNLOAD->isDoNotDownload());
		$this->assertFalse(FilePriority::NORMAL->isDoNotDownload());
		$this->assertFalse(FilePriority::HIGH->isDoNotDownload());
		$this->assertFalse(FilePriority::MAXIMUM->isDoNotDownload());
	}

	public function testFilePriorityIsNormal(): void
	{
		$this->assertTrue(FilePriority::NORMAL->isNormal());
		$this->assertFalse(FilePriority::DO_NOT_DOWNLOAD->isNormal());
		$this->assertFalse(FilePriority::HIGH->isNormal());
		$this->assertFalse(FilePriority::MAXIMUM->isNormal());
	}

	/**
	 * Both HIGH and MAXIMUM count as "high" — priority 6 and 7 are the same
	 * tier as far as callers are concerned.
	 */
	public function testFilePriorityIsHighCoversHighAndMaximum(): void
	{
		$this->assertTrue(FilePriority::HIGH->isHigh());
		$this->assertTrue(FilePriority::MAXIMUM->isHigh());
		$this->assertFalse(FilePriority::NORMAL->isHigh());
		$this->assertFalse(FilePriority::DO_NOT_DOWNLOAD->isHigh());
	}

	public function testTrackerStatusIsHealthy(): void
	{
		$this->assertTrue(TrackerStatus::WORKING->isHealthy());
		$this->assertFalse(TrackerStatus::DISABLED->isHealthy());
		$this->assertFalse(TrackerStatus::NOT_CONTACTED->isHealthy());
		$this->assertFalse(TrackerStatus::NOT_WORKING->isHealthy());
		$this->assertFalse(TrackerStatus::TRACKER_ERROR->isHealthy());
		$this->assertFalse(TrackerStatus::UNREACHABLE->isHealthy());
	}

	public function testTrackerStatusIsActiveExcludesOnlyDisabled(): void
	{
		$this->assertFalse(TrackerStatus::DISABLED->isActive());

		foreach (
            [
			TrackerStatus::NOT_CONTACTED,
			TrackerStatus::WORKING,
			TrackerStatus::NOT_WORKING,
			TrackerStatus::TRACKER_ERROR,
			TrackerStatus::UNREACHABLE,
            ] as $case
        ) {
			$this->assertTrue($case->isActive(), $case->name);
		}
	}

	public function testEveryEnumUsesTheHasLabelTrait(): void
	{
		foreach (self::enumProvider() as $name => [$enum]) {
			$this->assertContains(
				HasLabel::class,
				class_uses($enum) ?: [],
				$name . ' must provide label().',
			);
		}
	}
}
