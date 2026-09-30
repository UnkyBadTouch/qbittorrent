<?php

declare(strict_types=1);

namespace Blackout\Tests;

use Blackout\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Helper::class)]
final class HelperTest extends TestCase
{
	public function testConfigLoadsEveryConfPhpFile(): void
	{
		$dir = $this->makeConfDir(
            [
			'qbittorrent.php' => "<?php return ['qbittorrent' => ['host' => 'a', 'user' => 'root']];",
			'paths.php'      => "<?php return ['paths' => ['root' => '/srv']];",
            ]
        );

		$config = Helper::config($dir);

		$this->assertSame('a', $config->get('qbittorrent.host'));
		$this->assertSame('/srv', $config->get('paths.root'));
	}

	public function testConfigAppliesLocalOverridesLastRegardlessOfGlobOrder(): void
	{
		// 'a.local.php' sorts before 'b.php' alphabetically, but local overrides
		// must still win, so config() partitions rather than glob-ordering.
		$dir = $this->makeConfDir(
            [
			'b.php'         => "<?php return ['qbittorrent' => ['host' => 'from-b']];",
			'a.local.php'   => "<?php return ['qbittorrent' => ['host' => 'from-local']];",
            ]
        );

		$this->assertSame('from-local', Helper::config($dir)->get('qbittorrent.host'));
	}

	public function testConfigOnEmptyDirYieldsEmptyConfig(): void
	{
		$dir = sys_get_temp_dir() . '/qbt-conf-empty-' . bin2hex(random_bytes(6));
		mkdir($dir . '/conf', recursive: true);

		try {
			$config = Helper::config($dir);

			$this->assertSame([], $config->all());
		} finally {
			@rmdir($dir . '/conf');
			@rmdir($dir);
		}
	}

	public function testJsonEncodeKeepsUnicodeAndSlashesReadable(): void
	{
		$json = Helper::jsonEncode(['url' => 'https://a.test/b', 'name' => 'Ünïcödé']);

		$this->assertStringContainsString('https://a.test/b', $json, 'Slashes must not be escaped.');
		$this->assertStringContainsString('Ünïcödé', $json, 'Unicode must not be escaped.');
	}

	public function testJsonEncodeSubstitutesInvalidUtf8RatherThanFailing(): void
	{
		$json = Helper::jsonEncode(['bad' => "\xB1\x31"]);

		$this->assertIsString($json);
		// JSON_INVALID_UTF8_SUBSTITUTE emits U+FFFD per bad byte, not a silent drop.
		$this->assertSame(['bad' => "\u{FFFD}1"], json_decode($json, true));
	}

	public function testJsonDecodeReturnsAssociativeArrayByDefault(): void
	{
		$this->assertSame(['a' => ['b' => 1]], Helper::jsonDecode('{"a":{"b":1}}'));
	}

	public function testJsonDecodeReturnsObjectsWhenAssocIsFalse(): void
	{
		$decoded = Helper::jsonDecode('{"a":1}', false);

		$this->assertInstanceOf(\stdClass::class, $decoded);
		$this->assertSame(1, $decoded->a);
	}

	public function testJsonDecodeSubstitutesInvalidUtf8RatherThanFailing(): void
	{
		$this->assertSame(['bad' => "\u{FFFD}1"], Helper::jsonDecode("{\"bad\":\"\xB1\x31\"}"));
	}

	public function testJsonDecodeThrowsOnMalformedJson(): void
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('JSON decode failed');

		Helper::jsonDecode('{not json');
	}

	public function testIsCliIsTrueUnderTheTestRunner(): void
	{
		$this->assertTrue(Helper::isCli());
	}

	#[DataProvider('filesizeProvider')]
	public function testFilesize(int|float $bytes, int $precision, string $expected): void
	{
		$this->assertSame($expected, Helper::filesize($bytes, $precision));
	}

	public static function filesizeProvider(): array
	{
		return [
			'zero'                 => [0, 2, '0 B'],
			'negative'             => [-5, 2, '-5 B'],
			'bytes'                => [512, 2, '512 B'],
			'float bytes'          => [1023.5, 2, '1023.5 B'],
			'exact kb'             => [1024, 2, '1 KB'],
			'fractional kb'        => [1536, 2, '1.5 KB'],
			'mb'                   => [1048576, 2, '1 MB'],
			'gb'                   => [1073741824, 2, '1 GB'],
			'tb'                   => [1099511627776, 2, '1 TB'],
			'pb'                   => [1125899906842624, 2, '1 PB'],
			'clamped above pb'     => [1152921504606846976, 2, '1024 PB'],
			'precision zero'       => [1536, 0, '2 KB'],
			'precision four'       => [1234567, 4, '1.1774 MB'],
		];
	}

	public function testAtomicWriteCreatesFileAndLeavesNoTempFile(): void
	{
		$dir = sys_get_temp_dir() . '/qbt-atomic-' . bin2hex(random_bytes(6));
		mkdir($dir);
		$path = $dir . '/state.json';

		try {
			Helper::atomicWrite($path, '{"a":1}');

			$this->assertSame('{"a":1}', file_get_contents($path));
			$this->assertSame(
				[$path],
				glob($dir . '/*') ?: [],
				'The temp file must be renamed away, not left behind.',
			);
		} finally {
			@unlink($path);
			foreach (glob($dir . '/*.tmp.*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
	}

	public function testAtomicWriteOverwritesExistingContent(): void
	{
		$dir = sys_get_temp_dir() . '/qbt-atomic-' . bin2hex(random_bytes(6));
		mkdir($dir);
		$path = $dir . '/state.json';

		try {
			Helper::atomicWrite($path, 'first');
			Helper::atomicWrite($path, 'second');

			$this->assertSame('second', file_get_contents($path));
		} finally {
			@unlink($path);
			foreach (glob($dir . '/*.tmp.*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
	}

	public function testLogInterpolatesVarsAndAppendsNewlineUnderCli(): void
	{
		// format('u') emits microseconds, not milliseconds.
		$this->assertMatchesRegularExpression(
			'/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\] hello world\n$/',
			$this->captureLog(fn () => Helper::log('hello %s', ['world'])),
		);
	}

	public function testLogStripsTagsUnderCli(): void
	{
		$this->assertStringEndsWith("bold text\n", $this->captureLog(fn () => Helper::log('<b>bold</b> text')));
	}

	public function testLogWithEmptyTextWritesNothing(): void
	{
		$this->assertSame('', $this->captureLog(fn () => Helper::log('')));
	}

	/**
	 * Capture what log() emits.
	 *
	 * log() calls ob_flush() itself, which would defeat PHPUnit's own output
	 * buffering (and trip beStrictAboutOutputDuringTests). A buffer with a
	 * callback that stores the content and returns an empty string intercepts
	 * the flush, so the text is captured and never reaches PHPUnit.
	 */
	private function captureLog(callable $callback): string
	{
		$captured = '';

		ob_start(function (string $buffer) use (&$captured): string {
			$captured .= $buffer;

			return '';
		});

		try {
			$callback();
		} finally {
			ob_end_clean();
		}

		return $captured;
	}

	/**
	 * @param array<string, string> $files
	 */
	private function makeConfDir(array $files): string
	{
		$dir = sys_get_temp_dir() . '/qbt-conf-' . bin2hex(random_bytes(6));
		mkdir($dir . '/conf', recursive: true);

		foreach ($files as $name => $contents) {
			file_put_contents($dir . '/conf/' . $name, $contents);
		}

		// Registered for cleanup by the caller; kept alive for the test's duration.
		$this->confDirs[] = $dir;

		return $dir;
	}

	/** @var array<int, string> */
	private array $confDirs = [];

	protected function tearDown(): void
	{
		foreach ($this->confDirs as $dir) {
			foreach (glob($dir . '/conf/*') ?: [] as $file) {
				@unlink($file);
			}

			@rmdir($dir . '/conf');
			@rmdir($dir);
		}

		$this->confDirs = [];

		parent::tearDown();
	}
}
