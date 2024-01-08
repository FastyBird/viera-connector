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
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Writers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Connectors as DevicesConnectors;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Events as DevicesEvents;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use Nette;
use Psr\EventDispatcher as PsrEventDispatcher;
use React\EventLoop;
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

	public function __construct(
		private readonly DevicesEntities\Connectors\Connector $connector,
		private readonly Clients\ClientFactory $clientFactory,
		private readonly Clients\DiscoveryFactory $discoveryClientFactory,
		private readonly Writers\WriterFactory $writerFactory,
		private readonly Queue\Queue $queue,
		private readonly Queue\Consumers $consumers,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Configuration\Connectors\Repository $connectorsConfigurationRepository,
		private readonly EventLoop\LoopInterface $eventLoop,
		private readonly PsrEventDispatcher\EventDispatcherInterface|null $dispatcher = null,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	public function execute(): void
	{
		assert($this->connector instanceof Entities\VieraConnector);

		$this->logger->info(
			'Starting Viera connector service',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		$findConnector = new DevicesQueries\Configuration\FindConnectors();
		$findConnector->byId($this->connector->getId());
		$findConnector->byType(Entities\VieraConnector::TYPE);

		$connector = $this->connectorsConfigurationRepository->findOneBy($findConnector);

		if ($connector === null) {
			$this->logger->error(
				'Connector could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'connector',
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
				],
			);

			return;
		}

		$this->client = $this->clientFactory->create($connector);
		$this->client->connect();

		$this->writer = $this->writerFactory->create($connector);
		$this->writer->connect();

		$this->consumersTimer = $this->eventLoop->addPeriodicTimer(
			self::QUEUE_PROCESSING_INTERVAL,
			async(function (): void {
				$this->consumers->consume();
			}),
		);

		$this->logger->info(
			'Viera connector service has been started',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	public function discover(): void
	{
		assert($this->connector instanceof Entities\VieraConnector);

		$this->logger->info(
			'Starting Viera connector discovery',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		$findConnector = new DevicesQueries\Configuration\FindConnectors();
		$findConnector->byId($this->connector->getId());
		$findConnector->byType(Entities\VieraConnector::TYPE);

		$connector = $this->connectorsConfigurationRepository->findOneBy($findConnector);

		if ($connector === null) {
			$this->logger->error(
				'Connector could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'connector',
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
				],
			);

			return;
		}

		$this->client = $this->discoveryClientFactory->create($connector);

		$this->client->on('finished', function (): void {
			$this->dispatcher?->dispatch(
				new DevicesEvents\TerminateConnector(
					MetadataTypes\ConnectorSource::get(MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA),
					'Devices discovery finished',
				),
			);
		});

		$this->consumersTimer = $this->eventLoop->addPeriodicTimer(
			self::QUEUE_PROCESSING_INTERVAL,
			async(function (): void {
				$this->consumers->consume();
			}),
		);

		$this->logger->info(
			'Viera connector discovery has been started',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'connector',
				'connector' => [
					'id' => $this->connector->getId()->toString(),
				],
			],
		);

		$this->client->discover();
	}

	public function terminate(): void
	{
		$this->client?->disconnect();

		$this->writer?->disconnect();

		if ($this->consumersTimer !== null && $this->queue->isEmpty()) {
			$this->eventLoop->cancelTimer($this->consumersTimer);
		}

		$this->logger->info(
			'Viera connector has been terminated',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
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
