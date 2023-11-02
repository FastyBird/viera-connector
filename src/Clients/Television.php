<?php declare(strict_types = 1);

/**
 * Television.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 * @since          1.0.0
 *
 * @date           06.05.23
 */

namespace FastyBird\Connector\Viera\Clients;

use DateTimeInterface;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Types;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use React\EventLoop;
use Throwable;
use function array_key_exists;
use function assert;
use function in_array;
use function is_string;

/**
 * Television client
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Television implements Client
{

	use Nette\SmartObject;

	private const HANDLER_START_DELAY = 2.0;

	private const HANDLER_PROCESSING_INTERVAL = 0.01;

	/** @var array<string, API\TelevisionApi> */
	private array $devicesClients = [];

	/** @var array<string> */
	private array $processedDevices = [];

	/** @var array<string, array<string, DateTimeInterface|false>> */
	private array $processedChannelsProperties = [];

	private EventLoop\TimerInterface|null $handlerTimer = null;

	public function __construct(
		private readonly Entities\VieraConnector $connector,
		private readonly API\ConnectionManager $connectionManager,
		private readonly Queue\Queue $queue,
		private readonly Helpers\Entity $entityHelper,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesUtilities\DeviceConnection $deviceConnection,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	public function connect(): void
	{
		$this->processedDevices = [];

		$findDevicesQuery = new Queries\FindDevices();
		$findDevicesQuery->forConnector($this->connector);

		foreach ($this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class) as $device) {
			$this->createDeviceClient($device);
		}

		$this->eventLoop->addTimer(
			self::HANDLER_START_DELAY,
			function (): void {
				$this->registerLoopHandler();
			},
		);
	}

	public function disconnect(): void
	{
		foreach ($this->devicesClients as $client) {
			try {
				$client->disconnect();
			} catch (Throwable) {
				// Just ignore
			}
		}

		if ($this->handlerTimer !== null) {
			$this->eventLoop->cancelTimer($this->handlerTimer);

			$this->handlerTimer = null;
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function handleCommunication(): void
	{
		$findDevicesQuery = new Queries\FindDevices();
		$findDevicesQuery->forConnector($this->connector);

		foreach ($this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class) as $device) {
			if (
				!in_array($device->getId()->toString(), $this->processedDevices, true)
				&& !$this->deviceConnection->getState($device)->equalsValue(
					MetadataTypes\ConnectionState::STATE_ALERT,
				)
			) {
				$this->processedDevices[] = $device->getId()->toString();

				if ($this->processDevice($device)) {
					$this->registerLoopHandler();

					return;
				}
			}
		}

		$this->processedDevices = [];

		$this->registerLoopHandler();
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function processDevice(Entities\VieraDevice $device): bool
	{
		$client = $this->getDeviceClient($device);

		if ($client === null) {
			$this->createDeviceClient($device);

			return false;
		}

		if (!$client->isConnected()) {
			try {
				$client->connect(true);

				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector()->getId()->toString(),
							'device' => $device->getId()->toString(),
							'state' => MetadataTypes\ConnectionState::STATE_CONNECTED,
						],
					),
				);

			} catch (Exceptions\TelevisionApiCall $ex) {
				$this->logger->error('Calling device api failed', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				]);

				return false;
			} catch (Exceptions\TelevisionApiError $ex) {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector()->getId()->toString(),
							'device' => $device->getId()->toString(),
							'state' => MetadataTypes\ConnectionState::STATE_ALERT,
						],
					),
				);

				$this->logger->error('Connection to device could not be created', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				]);

				return false;
			} catch (Exceptions\InvalidState $ex) {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector()->getId()->toString(),
							'device' => $device->getId()->toString(),
							'state' => MetadataTypes\ConnectionState::STATE_ALERT,
						],
					),
				);

				try {
					$this->getDeviceClient($device)?->disconnect();
				} catch (Throwable) {
					// Just ignore
				}

				$this->logger->error('Device is in invalid state and could not be handled', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				]);

				return false;
			}
		}

		$findChannelsQuery = new DevicesQueries\FindChannels();
		$findChannelsQuery->forDevice($device);
		$findChannelsQuery->byIdentifier(Types\ChannelType::TELEVISION);

		$channel = $this->channelsRepository->findOneBy($findChannelsQuery);

		if ($channel === null) {
			$this->queue->append(
				$this->entityHelper->create(
					Entities\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $device->getConnector()->getId()->toString(),
						'device' => $device->getId()->toString(),
						'state' => MetadataTypes\ConnectionState::STATE_ALERT,
					],
				),
			);

			try {
				$this->getDeviceClient($device)?->disconnect();
			} catch (Throwable) {
				// Just ignore
			}

			return false;
		}

		$findChannelPropertiesQuery = new DevicesQueries\FindChannelProperties();
		$findChannelPropertiesQuery->forChannel($channel);

		foreach ($this->channelsPropertiesRepository->findAllBy($findChannelPropertiesQuery) as $property) {
			if (!$property instanceof DevicesEntities\Channels\Properties\Dynamic) {
				continue;
			}

			if (!array_key_exists($device->getId()->toString(), $this->processedChannelsProperties)) {
				$this->processedChannelsProperties[$device->getId()->toString()] = [];
			}

			if (array_key_exists(
				$property->getId()->toString(),
				$this->processedChannelsProperties[$device->getId()->toString()],
			)) {
				$cmdResult = $this->processedChannelsProperties[$device->getId()->toString()][$property->getId()->toString()];

				if (
					$cmdResult instanceof DateTimeInterface
					&& (
						$this->dateTimeFactory->getNow()->getTimestamp() - $cmdResult->getTimestamp() < $device->getStateReadingDelay()
					)
				) {
					return false;
				}
			}

			$this->processedChannelsProperties[$device->getId()->toString()][$property->getId()->toString()] = $this->dateTimeFactory->getNow();

			$result = null;

			try {
				switch ($property->getIdentifier()) {
					case Types\ChannelPropertyIdentifier::STATE:
						$result = $client->isTurnedOn();

						break;
					case Types\ChannelPropertyIdentifier::VOLUME:
						$result = $client->getVolume();

						break;
					case Types\ChannelPropertyIdentifier::MUTE:
						$result = $client->getMute();

						break;
				}
			} catch (Exceptions\TelevisionApiError $ex) {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector()->getId()->toString(),
							'device' => $device->getId()->toString(),
							'state' => MetadataTypes\ConnectionState::STATE_ALERT,
						],
					),
				);

				$this->logger->error('Preparing api request failed', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				]);

				continue;
			} catch (Exceptions\TelevisionApiCall $ex) {
				$this->logger->error('Calling device api failed', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				]);

				continue;
			} catch (Exceptions\InvalidState $ex) {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector()->getId()->toString(),
							'device' => $device->getId()->toString(),
							'state' => MetadataTypes\ConnectionState::STATE_ALERT,
						],
					),
				);

				try {
					$this->getDeviceClient($device)?->disconnect();
				} catch (Throwable) {
					// Just ignore
				}

				$this->logger->error('Device is in invalid state and could not be handled', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				]);

				return false;
			}

			if ($result === null) {
				continue;
			}

			$result
				->then(function (int|bool $value) use ($device, $channel, $property): void {
					// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
					$this->processedChannelsProperties[$device->getId()->toString()][$property->getId()->toString()] = $this->dateTimeFactory->getNow();

					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $device->getConnector()->getId()->toString(),
								'device' => $device->getId()->toString(),
								'channel' => $channel->getIdentifier(),
								'property' => $property->getIdentifier(),
								'value' => $value,
							],
						),
					);
				})
				->otherwise(function (Throwable $ex) use ($device, $property): void {
					$this->processedChannelsProperties[$device->getId()->toString()][$property->getId()->toString()] = false;

					$this->logger->warning(
						'Could not call local api',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'television-client',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
							'connector' => [
								'id' => $this->connector->getId()->toString(),
							],
							'device' => [
								'id' => $device->getId()->toString(),
							],
						],
					);

					if ($ex instanceof Exceptions\TelevisionApiError) {
						$this->queue->append(
							$this->entityHelper->create(
								Entities\Messages\StoreDeviceConnectionState::class,
								[
									'connector' => $device->getConnector()->getId()->toString(),
									'device' => $device->getId()->toString(),
									'state' => MetadataTypes\ConnectionState::STATE_ALERT,
								],
							),
						);
					} elseif ($ex->getCode() === 500) {
						$this->queue->append(
							$this->entityHelper->create(
								Entities\Messages\StoreDeviceConnectionState::class,
								[
									'connector' => $device->getConnector()->getId()->toString(),
									'device' => $device->getId()->toString(),
									'state' => MetadataTypes\ConnectionState::STATE_DISCONNECTED,
								],
							),
						);
					}
				});
		}

		return true;
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function createDeviceClient(Entities\VieraDevice $device): void
	{
		unset($this->processedChannelsProperties[$device->getId()->toString()]);

		assert(is_string($device->getIpAddress()));

		$client = $this->connectionManager->getConnection($device);

		$client->on(
			'event-data',
			function (Entities\API\Event $event) use ($device): void {
				if ($event->getScreenState() !== null) {
					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $device->getConnector()->getId()->toString(),
								'device' => $device->getId()->toString(),
								'channel' => Types\ChannelType::TELEVISION,
								'property' => Types\ChannelPropertyIdentifier::STATE,
								'value' => $event->getScreenState(),
							],
						),
					);
				}
			},
		);

		$client->on(
			'event-error',
			function (Throwable $ex) use ($device): void {
				$this->logger->warning(
					'Event subscription with device failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'television-client',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
						'connector' => [
							'id' => $this->connector->getId()->toString(),
						],
						'device' => [
							'id' => $device->getId()->toString(),
						],
					],
				);

				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector()->getId()->toString(),
							'device' => $device->getId()->toString(),
							'state' => MetadataTypes\ConnectionState::STATE_DISCONNECTED,
						],
					),
				);
			},
		);

		$this->devicesClients[$device->getId()->toString()] = $client;
	}

	private function getDeviceClient(Entities\VieraDevice $device): API\TelevisionApi|null
	{
		return array_key_exists(
			$device->getId()->toString(),
			$this->devicesClients,
		)
			? $this->devicesClients[$device->getId()->toString()]
			: null;
	}

	private function registerLoopHandler(): void
	{
		$this->handlerTimer = $this->eventLoop->addTimer(
			self::HANDLER_PROCESSING_INTERVAL,
			function (): void {
				$this->handleCommunication();
			},
		);
	}

}
