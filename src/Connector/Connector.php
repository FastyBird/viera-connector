<?php declare(strict_types = 1);

/**
 * Connector.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Connector
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Connector;

use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Documents;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Writers;
use FastyBird\Library\Exchange\Exceptions as ExchangeExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Connectors as DevicesConnectors;
use FastyBird\Module\Devices\Documents as DevicesDocuments;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use Nette;
use React\EventLoop;
use React\Promise;
use function assert;
use function React\Async\async;

/**
 * Connector service executor
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Connector
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Connector implements DevicesConnectors\Connector
{

	use Nette\SmartObject;

	private const QUEUE_PROCESSING_INTERVAL = 0.01;

	private Clients\Client|Clients\Discovery|null $client = null;

	private Writers\Writer|null $writer = null;

	private EventLoop\TimerInterface|null $consumersTimer = null;

	/**
	 * @param array<Writers\WriterFactory> $writersFactories
	 */
	public function __construct(
		private readonly DevicesDocuments\Connectors\Connector $connector,
		private readonly Clients\ClientFactory $clientFactory,
		private readonly Clients\DiscoveryFactory $discoveryClientFactory,
		private readonly array $writersFactories,
		private readonly Queue\Queue $queue,
		private readonly Queue\Consumers $consumers,
		private readonly Viera\Logger $logger,
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
		assert($this->connector instanceof Documents\Connectors\Connector);
	}

	/**
	 * @return Promise\PromiseInterface<bool>
	 *
	 * @throws DevicesExceptions\InvalidState
	 * @throws ExchangeExceptions\InvalidArgument
	 */
	public function execute(bool $standalone = true): Promise\PromiseInterface
	{
		assert($this->connector instanceof Documents\Connectors\Connector);

		$this->logger->info(
			'Starting Viera connector service',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		$this->client = $this->clientFactory->create($this->connector);
		$this->client->connect();

		foreach ($this->writersFactories as $writerFactory) {
			if (
				(
					$standalone
					&& $writerFactory instanceof Writers\ExchangeFactory
				) || (
					!$standalone
					&& $writerFactory instanceof Writers\EventFactory
				)
			) {
				$this->writer = $writerFactory->create($this->connector);
				$this->writer->connect();
			}
		}

		$this->consumersTimer = $this->eventLoop->addPeriodicTimer(
			self::QUEUE_PROCESSING_INTERVAL,
			async(function (): void {
				$this->consumers->consume();
			}),
		);

		$this->logger->info(
			'Viera connector service has been started',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		return Promise\resolve(true);
	}

	/**
	 * @return Promise\PromiseInterface<bool>
	 */
	public function discover(): Promise\PromiseInterface
	{
		assert($this->connector instanceof Documents\Connectors\Connector);

		$this->logger->info(
			'Starting Viera connector discovery',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		$this->client = $this->discoveryClientFactory->create($this->connector);

		$this->consumersTimer = $this->eventLoop->addPeriodicTimer(
			self::QUEUE_PROCESSING_INTERVAL,
			async(function (): void {
				$this->consumers->consume();
			}),
		);

		$this->logger->info(
			'Viera connector discovery has been started',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		$this->client->discover();

		return Promise\resolve(true);
	}

	public function terminate(): void
	{
		assert($this->connector instanceof Documents\Connectors\Connector);

		$this->client?->disconnect();

		$this->writer?->disconnect();

		if ($this->consumersTimer !== null && $this->queue->isEmpty()) {
			$this->eventLoop->cancelTimer($this->consumersTimer);
		}

		$this->logger->info(
			'Viera connector has been terminated',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);
	}

	public function hasUnfinishedTasks(): bool
	{
		return !$this->queue->isEmpty() && $this->consumersTimer !== null;
	}

}
