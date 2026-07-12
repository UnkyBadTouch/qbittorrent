<?php

namespace Blackout;

final class FileLock
{
	protected $handle = null;

	public function __construct(
		protected ?string $file = null,
		protected int $ttl = 600,
	)
	{
		register_shutdown_function([$this, 'release']);

		if (extension_loaded('pcntl'))
		{
			pcntl_async_signals(true);

			pcntl_signal(SIGINT, function ()
			{
				$this->release();

				exit;
			});

			pcntl_signal(SIGTERM, function ()
			{
				$this->release();

				exit;
			});

			pcntl_signal(SIGHUP, function ()
			{
				$this->release();

				exit;
			});
		}

		if (!$this->file)
		{
			$script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['argv'][0] ?? null;

			if (!$script) throw new \RuntimeException('Unable to determine calling script');

			$script = realpath($script);

			if ($script === false) throw new \RuntimeException('Unable to resolve script path');

			$this->file = $script . '.lock';
		}

		$this->acquire();
	}

	public function __destruct()
	{
		$this->release();
	}

	public function acquire(): static
	{
		// first attempt
		$this->handle = @fopen($this->file, 'x');

		if (!$this->handle)
		{
			// check for stale lock
			if ($this->isStale())
			{
				@unlink($this->file);

				// retry once after cleanup
				$this->handle = @fopen($this->file, 'x');
			}
		}

		if (!$this->handle)
		{
			throw new \RuntimeException('Lock already exists: ' . $this->file);
		}

		// write PID
		fwrite($this->handle, (string) getmypid());
		fflush($this->handle);

		return $this;
	}

	public function release(): static
	{
		if ($this->handle)
		{
			fclose($this->handle);
			@unlink($this->file);
		}

		$this->handle = null;

		return $this;
	}

	protected function isStale(): bool
	{
		if (!file_exists($this->file))
		{
			return false;
		}

		// PID check first — a live owner is never stale, regardless of lock age.
		// (Long-running daemons write the lock once; mtime alone would mark them
		// stale after the TTL and let a second instance steal the lock.)
		$pid = trim(@file_get_contents($this->file));

		if ($pid && is_numeric($pid) && function_exists('posix_kill'))
		{
			if (@posix_kill((int) $pid, 0))
			{
				return false;
			}

			// EPERM (1): process exists but is owned by another user — still alive
			if (function_exists('posix_get_last_error') && posix_get_last_error() === 1)
			{
				return false;
			}

			return true;
		}

		// No usable PID — fall back to TTL
		$mtime = @filemtime($this->file);

		return $mtime !== false && (time() - $mtime) > $this->ttl;
	}
}
