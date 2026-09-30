<?php

declare(strict_types=1);

namespace Blackout\Tests\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle handler that answers from an editable queue.
 *
 * Guzzle's own MockHandler consumes its queue as it goes and offers no way to
 * swap an already-queued response. Several auth tests need to know a response's
 * shape before queueing it (a 204 has to carry its Set-Cookie header) and
 * others need to edit a slot after the fact, so the queue is kept here and
 * read by index.
 *
 * When the queue runs dry the next request gets an empty 200, which surfaces a
 * test that queued too few responses as a parse failure rather than as a
 * hang or an unrelated error.
 */
final class QueueHandler
{
	/** @var array<int, \GuzzleHttp\Psr7\Response|\Throwable> */
	private array $queue = [];

	/** @var array<int, RequestInterface> */
	private array $requests = [];

	public function append(\GuzzleHttp\Psr7\Response|\Throwable $item): void
	{
		$this->queue[] = $item;
	}

	/**
	 * Replace the response at a queue position before it is consumed.
	 */
	public function replace(int $index, \GuzzleHttp\Psr7\Response $response): void
	{
		$this->queue[$index] = $response;
	}

	/** @return array<int, RequestInterface> */
	public function requests(): array
	{
		return $this->requests;
	}

	public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
	{
		$this->requests[] = $request;

		$item = array_shift($this->queue);

		if ($item === null) {
			return Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, [], '{}'));
		}

		if ($item instanceof \Throwable) {
			return Create::rejectionFor($item);
		}

		return Create::promiseFor($item);
	}
}
