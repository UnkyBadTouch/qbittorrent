<?php

declare(strict_types=1);

namespace Blackout\Tests\Qbittorrent\DTO;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\DTO\Cookie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cookie::class)]
final class CookieTest extends TestCase
{
	public function testHydratesThePlainFields(): void
	{
		$cookie = new Cookie(
            [
			'name'           => 'SID',
			'domain'         => 'qbt.test',
			'path'           => '/',
			'value'          => 'abc123',
			'expirationDate' => 1750000000,
            ]
		);

		$this->assertSame('SID', $cookie->name);
		$this->assertSame('qbt.test', $cookie->domain);
		$this->assertSame('/', $cookie->path);
		$this->assertSame('abc123', $cookie->value);
		$this->assertSame(1750000000, $cookie->expirationDate);
	}

	/**
	 * Browser cookie databases and Netscape files express expiry as a date
	 * string, so the setter has to parse it down to a timestamp.
	 */
	#[DataProvider('expirationProvider')]
	public function testExpirationSetterAcceptsMultipleInputTypes(mixed $input, int $expected): void
	{
		$cookie = new Cookie(['name' => 'SID']);

		$cookie->expirationDate = $input;

		$this->assertSame($expected, $cookie->expirationDate);
	}

	public static function expirationProvider(): array
	{
		// Timestamps below are the real values for 2025-06-15 under the test
		// runner's UTC default timezone, not hand-computed round numbers.
		return [
			'int passthrough' => [1750000000, 1750000000],
			'iso date'        => ['2025-06-15 12:00:00', 1749988800],
			'date only'       => ['2025-06-15', 1749945600],
			'rfc2822'         => ['Sun, 15 Jun 2025 12:00:00 +0000', 1749988800],
			'zero is kept'    => [0, 0],
		];
	}

	public function testExpirationSetterAcceptsADateTimeInstance(): void
	{
		$cookie = new Cookie(['name' => 'SID']);

		$cookie->expirationDate = new \DateTimeImmutable('@1750000000');

		$this->assertSame(1750000000, $cookie->expirationDate);
	}

	public function testExpirationSetterFiresDuringHydration(): void
	{
		// Base::hydrate() writes through setValue(), which must trigger the
		// same hook as a direct assignment, or string dates would survive as
		// strings and break any comparison against time().
		$cookie = new Cookie(['name' => 'SID', 'expirationDate' => '2025-06-15 12:00:00']);

		$this->assertSame(1749988800, $cookie->expirationDate);
	}

	/**
	 * The setter's is_string() branch runs before the int passthrough, so a
	 * numeric string is handed to DateTimeImmutable, which rejects it. Only a
	 * real int survives as a timestamp.
	 */
	public function testNumericStringExpiryIsRejected(): void
	{
		$cookie = new Cookie(['name' => 'SID']);

		$this->expectException(\DateMalformedStringException::class);

		$cookie->expirationDate = '1750000000';
	}

	public function testJsonSerializeIncludesTheExpiryAsAnInt(): void
	{
		$cookie = new Cookie(['name' => 'SID', 'expirationDate' => '2025-06-15 12:00:00']);

		$this->assertSame(1749988800, $cookie->jsonSerialize()['expirationDate']);
	}

	public function testCookieDoesNotRequireAClient(): void
	{
		$cookie = new Cookie(['name' => 'SID']);

		$this->assertSame('SID', $cookie->name);
	}
}
