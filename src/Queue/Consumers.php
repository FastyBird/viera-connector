<?php declare(strict_types = 1);

/**
 * Consumers.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 * @since          1.0.0
 *
 * @date           10.08.23
 */

namespace FastyBird\Connector\Viera\Queue;

use FastyBird\Connector\Viera;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use Nette;
use SplObjectStorage;

/**
 * Clients message queue consumers container
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Consumers
{

	use Nette\SmartObject;

	/** @var SplObjectStorage<Consumer, null> */
	private SplObjectStorage $consumers;

	/**
	 * @param array<Consumer> $consumers
	 */
	public function __construct(
		array $consumers,
		private readonly Queue $queue,
		private readonly Viera\Logger $logger,
	)
	{
		$this->consumers = new SplObjectStorage();

		foreach ($consumers as $consumer) {
			$this->append($consumer);
		}
	}

	public function append(Consumer $consumer): void
	{
		$this->consumers->attach($consumer);

		$this->logger->debug(
			'Appended new messages consumer',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'consumers',
			],
		);
	}

	public function consume(): void
	{
		$message = $this->queue->dequeue();

		if ($message === false) {
			return;
		}

		$this->consumers->rewind();

		if ($this->consumers->count() === 0) {
			$this->logger->error(
				'No consumer is registered, message could not be consumed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'consumers',
				],
			);

			return;
		}

		foreach ($this->consumers as $consumer) {
			if ($consumer->consume($message) === true) {
				return;
			}
		}

		$this->logger->error(
			'Message could not be consumed',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'consumers',
				'message' => $message->toArray(),
			],
		);
	}

}
