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
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Types;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Documents as MetadataDocuments;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
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

	private const RECONNECT_COOL_DOWN_TIME = 300.0;

	/** @var array<string, MetadataDocuments\DevicesModule\Device>  */
	private array $devices = [];

	/** @var array<string, array<string, MetadataDocuments\DevicesModule\ChannelDynamicProperty>>  */
	private array $properties = [];

	/** @var array<string, API\TelevisionApi> */
	private array $devicesClients = [];

	/** @var array<string> */
	private array $processedDevices = [];

	/** @var array<string, array<string, DateTimeInterface|false>> */
	private array $processedChannelsProperties = [];

	private EventLoop\TimerInterface|null $handlerTimer = null;

	public function __construct(
		private readonly MetadataDocuments\DevicesModule\Connector $connector,
		private readonly API\ConnectionManager $connectionManager,
		private readonly Queue\Queue $queue,
		private readonly Helpers\Entity $entityHelper,
		private readonly Helpers\Device $deviceHelper,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Configuration\Devices\Repository $devicesConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Repository $channelsConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Properties\Repository $channelsPropertiesConfigurationRepository,
		private readonly DevicesUtilities\DeviceConnection $deviceConnectionManager,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	public function connect(): void
	{
		$this->processedDevices = [];
		$this->processedChannelsProperties = [];

		$findDevicesQuery = new DevicesQueries\Configuration\FindDevices();
		$findDevicesQuery->forConnector($this->connector);
		$findDevicesQuery->byType(Entities\VieraDevice::TYPE);

		foreach ($this->devicesConfigurationRepository->findAllBy($findDevicesQuery) as $device) {
			if (!array_key_exists($device->getId()->toString(), $this->properties)) {
				$this->properties[$device->getId()->toString()] = [];
			}

			$findChannelQuery = new DevicesQueries\Configuration\FindChannels();
			$findChannelQuery->forDevice($device);
			$findChannelQuery->byIdentifier(Types\ChannelType::TELEVISION);

			$channel = $this->channelsConfigurationRepository->findOneBy($findChannelQuery);

			if ($channel === null) {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => MetadataTypes\ConnectionState::STATE_ALERT,
						],
					),
				);

				continue;
			}

			$findChannelPropertiesQuery = new DevicesQueries\Configuration\FindChannelDynamicProperties();
			$findChannelPropertiesQuery->forChannel($channel);

			$properties = $this->channelsPropertiesConfigurationRepository->findAllBy(
				$findChannelPropertiesQuery,
				MetadataDocuments\DevicesModule\ChannelDynamicProperty::class,
			);

			foreach ($properties as $property) {
				if ($property->isSettable()) {
					$this->properties[$device->getId()->toString()][$property->getId()->toString()] = $property;
				}
			}

			$this->devices[$device->getId()->toString()] = $device;

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
	 * @throws DevicesExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\MalformedInput
	 */
	private function handleCommunication(): void
	{
		foreach ($this->devices as $device) {
			if (!in_array($device->getId()->toString(), $this->processedDevices, true)) {
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
	 * @throws DevicesExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\MalformedInput
	 */
	private function processDevice(MetadataDocuments\DevicesModule\Device $device): bool
	{
		$client = $this->getDeviceClient($device);

		if ($client === null) {
			$this->createDeviceClient($device);

			return false;
		}

		if (!$client->isConnected()) {
			$deviceState = $this->deviceConnectionManager->getState($device);

			if ($deviceState->equalsValue(MetadataTypes\ConnectionState::STATE_ALERT)) {
				unset($this->devices[$device->getId()->toString()]);

				return false;
			}

			if (
				$client->getLastConnectAttempt() === null
				|| (
					// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
					$this->dateTimeFactory->getNow()->getTimestamp() - $client->getLastConnectAttempt()->getTimestamp() >= self::RECONNECT_COOL_DOWN_TIME
				)
			) {
				try {
					$client->connect(true);

					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreDeviceConnectionState::class,
							[
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
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
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
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
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
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
			} else {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => MetadataTypes\ConnectionState::STATE_DISCONNECTED,
						],
					),
				);
			}

			return false;
		}

		foreach ($this->properties[$device->getId()->toString()] as $property) {
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
						$this->dateTimeFactory->getNow()->getTimestamp() - $cmdResult->getTimestamp()
						< $this->deviceHelper->getStateReadingDelay($device)
					)
				) {
					return false;
				}
			}

			$this->processedChannelsProperties[$device->getId()->toString()][$property->getId()->toString()] = $this->dateTimeFactory->getNow();

			$deviceState = $this->deviceConnectionManager->getState($device);

			if ($deviceState->equalsValue(MetadataTypes\ConnectionState::STATE_ALERT)) {
				unset($this->devices[$device->getId()->toString()]);

				return false;
			}

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
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
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
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
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
				->then(function (int|bool $value) use ($device, $property): void {
					// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
					$this->processedChannelsProperties[$device->getId()->toString()][$property->getId()->toString()] = $this->dateTimeFactory->getNow();

					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
								'channel' => $property->getChannel(),
								'property' => $property->getId(),
								'value' => $value,
							],
						),
					);
				})
				->catch(function (Throwable $ex) use ($device, $property): void {
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
									'connector' => $device->getConnector(),
									'device' => $device->getId(),
									'state' => MetadataTypes\ConnectionState::STATE_ALERT,
								],
							),
						);
					} elseif ($ex->getCode() === 500) {
						$this->queue->append(
							$this->entityHelper->create(
								Entities\Messages\StoreDeviceConnectionState::class,
								[
									'connector' => $device->getConnector(),
									'device' => $device->getId(),
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
	private function createDeviceClient(MetadataDocuments\DevicesModule\Device $device): void
	{
		unset($this->processedChannelsProperties[$device->getId()->toString()]);

		assert(is_string($this->deviceHelper->getIpAddress($device)));

		$client = $this->connectionManager->getConnection($device);

		$client->on(
			'event-data',
			function (Entities\API\Event $event) use ($device): void {
				if ($event->getScreenState() !== null) {
					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
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
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => MetadataTypes\ConnectionState::STATE_DISCONNECTED,
						],
					),
				);
			},
		);

		$this->devicesClients[$device->getId()->toString()] = $client;
	}

	private function getDeviceClient(MetadataDocuments\DevicesModule\Device $device): API\TelevisionApi|null
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
