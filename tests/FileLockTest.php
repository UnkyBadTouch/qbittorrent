<?php

declare(strict_types=1);

namespace Blackout\Tests;

use Blackout\FileLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileLock::class)]
final class FileLockTest extends TestCase
{
	private string $dir;

	/** @var array<int, FileLock> */
	private array $locks = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/qbt-lock-' . bin2hex(random_bytes(6));
		mkdir($this->dir, recursive: true);
	}

	protected function tearDown(): void
	{
		foreach ($this->locks as $lock) {
			$lock->release();
		}

		$this->locks = [];

		foreach (glob($this->dir . '/*') ?: [] as $file) {
			@unlink($file);
		}

		@rmdir($this->dir);

		parent::tearDown();
	}

	public function testAcquiringCreatesLockFileContainingThePid(): void
	{
		$path = $this->dir . '/job.lock';

		$lock = $this->lock($path);

		$this->assertFileExists($path);
		$this->assertSame((string) getmypid(), trim(file_get_contents($path)));
		$this->assertInstanceOf(FileLock::class, $lock);
	}

	public function testAcquireThrowsWhenCalledAgainWhileHoldingTheLock(): void
	{
		// A second acquire() on a held lock re-runs the 'x' open, which fails,
		// and the PID check finds us alive — so it throws rather than
		// re-acquiring or truncating the file.
		$lock = $this->lock($this->dir . '/job.lock');

		$this->expectException(\RuntimeException::class);

		$lock->acquire();
	}

	public function testSecondInstanceOnAHeldLockThrows(): void
	{
		$path = $this->dir . '/job.lock';

		$this->lock($path);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Lock already exists: ' . $path);

		new FileLock($path);
	}

	public function testReleaseRemovesTheLockFileAndAllowsReacquisition(): void
	{
		$path = $this->dir . '/job.lock';

		$first = $this->lock($path);

		$this->assertSame($first, $first->release(), 'release() is fluent.');
		$this->assertFileDoesNotExist($path);

		$second = $this->lock($path);

		$this->assertFileExists($path);
	}

	public function testReleaseIsIdempotent(): void
	{
		$lock = $this->lock($this->dir . '/job.lock');

		$lock->release();
		$lock->release();

		$this->assertFileDoesNotExist($this->dir . '/job.lock');
	}

	/**
	 * The constructor registers a shutdown function holding a reference to
	 * $this, so the object stays alive until the process exits and __destruct
	 * does NOT run when the last userland reference goes away. This pins that
	 * behaviour so a future change to the shutdown registration is caught
	 * rather than silently altering when locks are dropped.
	 */
	public function testLockOutlivesUnsettingTheLastUserlandReference(): void
	{
		$path = $this->dir . '/job.lock';

		$this->lock($path);

		$this->locks = [];

		$this->assertFileExists(
			$path,
			'The shutdown-function reference keeps the lock held after unset().',
		);
	}

	public function testLockHeldByADeadPidIsStolen(): void
	{
		$path = $this->dir . '/job.lock';

		// A PID that is almost certainly not running. 2^22 is above the
		// default pid_max on Linux, so posix_kill() reports ESRCH.
		file_put_contents($path, (string) (1 << 22));
		touch($path, time());

		$lock = $this->lock($path);

		$this->assertSame((string) getmypid(), trim(file_get_contents($path)));
		$this->assertInstanceOf(FileLock::class, $lock);
	}

	public function testLockHeldByTheCurrentPidIsNotStolenEvenWhenOlderThanTtl(): void
	{
		$path = $this->dir . '/job.lock';

		// The TTL is 1s and the mtime is an hour old, but the PID is us, so
		// the lock is live and must survive — the PID check runs first.
		file_put_contents($path, (string) getmypid());
		touch($path, time() - 3600);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Lock already exists: ' . $path);

		new FileLock($path, 1);
	}

	public function testLockWithNoUsablePidFallsBackToTtl(): void
	{
		$path = $this->dir . '/job.lock';

		// No numeric PID at all, so isStale() can only consult the mtime.
		file_put_contents($path, 'not-a-pid');
		touch($path, time() - 3600);

		$lock = $this->lock($path, 60);

		$this->assertSame((string) getmypid(), trim(file_get_contents($path)));
		$this->assertInstanceOf(FileLock::class, $lock);
	}

	public function testLockWithNoUsablePidInsideTtlIsRespected(): void
	{
		$path = $this->dir . '/job.lock';

		file_put_contents($path, 'not-a-pid');
		touch($path);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Lock already exists: ' . $path);

		new FileLock($path, 3600);
	}

	public function testDefaultLockPathIsDerivedFromTheCallingScript(): void
	{
		$lock = new FileLock(null, 600);

		$this->locks[] = $lock;

		$expected = realpath($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['argv'][0]);

		$this->assertFileExists($expected . '.lock');
		$this->assertSame((string) getmypid(), trim(file_get_contents($expected . '.lock')));
	}

	private function lock(string $path, int $ttl = 600): FileLock
	{
		$lock = new FileLock($path, $ttl);

		$this->locks[] = $lock;

		return $lock;
	}
}
