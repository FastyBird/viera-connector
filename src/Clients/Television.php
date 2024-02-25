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
use FastyBird\Connector\Viera\Documents;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Types;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Application\Helpers as ApplicationHelpers;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Library\Tools\Exceptions as ToolsExceptions;
use FastyBird\Module\Devices\Documents as DevicesDocuments;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\Types as DevicesTypes;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use React\EventLoop;
use Throwable;
use TypeError;
use ValueError;
use function array_key_exists;
use function assert;
use function in_array;
use function is_string;
use function React\Async\async;

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

	/** @var array<string, Documents\Devices\Device>  */
	private array $devices = [];

	/** @var array<string, array<string, DevicesDocuments\Channels\Properties\Dynamic>>  */
	private array $properties = [];

	/** @var array<string, API\TelevisionApi> */
	private array $devicesClients = [];

	/** @var array<string> */
	private array $processedDevices = [];

	/** @var array<string, array<string, DateTimeInterface|false>> */
	private array $processedChannelsProperties = [];

	private EventLoop\TimerInterface|null $handlerTimer = null;

	public function __construct(
		private readonly Documents\Connectors\Connector $connector,
		private readonly API\ConnectionManager $connectionManager,
		private readonly Queue\Queue $queue,
		private readonly Helpers\MessageBuilder $messageBuilder,
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
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	public function connect(): void
	{
		$this->processedDevices = [];
		$this->processedChannelsProperties = [];

		$findDevicesQuery = new Queries\Configuration\FindDevices();
		$findDevicesQuery->forConnector($this->connector);

		$devices = $this->devicesConfigurationRepository->findAllBy(
			$findDevicesQuery,
			Documents\Devices\Device::class,
		);

		foreach ($devices as $device) {
			if (!array_key_exists($device->getId()->toString(), $this->properties)) {
				$this->properties[$device->getId()->toString()] = [];
			}

			$findChannelQuery = new Queries\Configuration\FindChannels();
			$findChannelQuery->forDevice($device);
			$findChannelQuery->byIdentifier(Types\ChannelType::TELEVISION);

			$channel = $this->channelsConfigurationRepository->findOneBy(
				$findChannelQuery,
				Documents\Channels\Channel::class,
			);

			if ($channel === null) {
				$this->queue->append(
					$this->messageBuilder->create(
						Queue\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => DevicesTypes\ConnectionState::ALERT,
						],
					),
				);

				continue;
			}

			$findChannelPropertiesQuery = new DevicesQueries\Configuration\FindChannelDynamicProperties();
			$findChannelPropertiesQuery->forChannel($channel);
			$findChannelPropertiesQuery->settable(true);

			$properties = $this->channelsPropertiesConfigurationRepository->findAllBy(
				$findChannelPropertiesQuery,
				DevicesDocuments\Channels\Properties\Dynamic::class,
			);

			foreach ($properties as $property) {
				$this->properties[$device->getId()->toString()][$property->getId()->toString()] = $property;
			}

			$this->devices[$device->getId()->toString()] = $device;

			$this->createDeviceClient($device);
		}

		$this->eventLoop->addTimer(
			self::HANDLER_START_DELAY,
			async(function (): void {
				$this->registerLoopHandler();
			}),
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
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\Mapping
	 * @throws MetadataExceptions\MalformedInput
	 * @throws ToolsExceptions\InvalidArgument
	 * @throws TypeError
	 * @throws ValueError
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
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\Mapping
	 * @throws MetadataExceptions\MalformedInput
	 * @throws ToolsExceptions\InvalidArgument
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function processDevice(Documents\Devices\Device $device): bool
	{
		$client = $this->getDeviceClient($device);

		if ($client === null) {
			$this->createDeviceClient($device);

			return false;
		}

		if (!$client->isConnected()) {
			$deviceState = $this->deviceConnectionManager->getState($device);

			if ($deviceState === DevicesTypes\ConnectionState::ALERT) {
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
						$this->messageBuilder->create(
							Queue\Messages\StoreDeviceConnectionState::class,
							[
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
								'state' => DevicesTypes\ConnectionState::CONNECTED,
							],
						),
					);

				} catch (Exceptions\TelevisionApiCall $ex) {
					$this->logger->error(
						'Calling device api failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'television-client',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
							'connector' => [
								'id' => $this->connector->getId()->toString(),
							],
							'device' => [
								'id' => $device->getId()->toString(),
							],
						],
					);

					return false;
				} catch (Exceptions\TelevisionApiError $ex) {
					$this->queue->append(
						$this->messageBuilder->create(
							Queue\Messages\StoreDeviceConnectionState::class,
							[
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
								'state' => DevicesTypes\ConnectionState::ALERT,
							],
						),
					);

					$this->logger->error(
						'Connection to device could not be created',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'television-client',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
							'connector' => [
								'id' => $this->connector->getId()->toString(),
							],
							'device' => [
								'id' => $device->getId()->toString(),
							],
						],
					);

					return false;
				} catch (Exceptions\InvalidState $ex) {
					$this->queue->append(
						$this->messageBuilder->create(
							Queue\Messages\StoreDeviceConnectionState::class,
							[
								'connector' => $device->getConnector(),
								'device' => $device->getId(),
								'state' => DevicesTypes\ConnectionState::ALERT,
							],
						),
					);

					try {
						$this->getDeviceClient($device)?->disconnect();
					} catch (Throwable) {
						// Just ignore
					}

					$this->logger->error(
						'Device is in invalid state and could not be handled',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'television-client',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
							'connector' => [
								'id' => $this->connector->getId()->toString(),
							],
							'device' => [
								'id' => $device->getId()->toString(),
							],
						],
					);

					return false;
				}
			} else {
				$this->queue->append(
					$this->messageBuilder->create(
						Queue\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => DevicesTypes\ConnectionState::DISCONNECTED,
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

			if ($deviceState === DevicesTypes\ConnectionState::ALERT) {
				unset($this->devices[$device->getId()->toString()]);

				return false;
			}

			$result = null;

			try {
				switch ($property->getIdentifier()) {
					case Types\ChannelPropertyIdentifier::STATE->value:
						$result = $client->isTurnedOn();

						break;
					case Types\ChannelPropertyIdentifier::VOLUME->value:
						$result = $client->getVolume();

						break;
					case Types\ChannelPropertyIdentifier::MUTE->value:
						$result = $client->getMute();

						break;
				}
			} catch (Exceptions\TelevisionApiError $ex) {
				$this->queue->append(
					$this->messageBuilder->create(
						Queue\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => DevicesTypes\ConnectionState::ALERT,
						],
					),
				);

				$this->logger->error(
					'Preparing api request failed',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'television-client',
						'exception' => ApplicationHelpers\Logger::buildException($ex),
						'connector' => [
							'id' => $this->connector->getId()->toString(),
						],
						'device' => [
							'id' => $device->getId()->toString(),
						],
					],
				);

				continue;
			} catch (Exceptions\TelevisionApiCall $ex) {
				$this->logger->error(
					'Calling device api failed',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'television-client',
						'exception' => ApplicationHelpers\Logger::buildException($ex),
						'connector' => [
							'id' => $this->connector->getId()->toString(),
						],
						'device' => [
							'id' => $device->getId()->toString(),
						],
					],
				);

				continue;
			} catch (Exceptions\InvalidState $ex) {
				$this->queue->append(
					$this->messageBuilder->create(
						Queue\Messages\StoreDeviceConnectionState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'state' => DevicesTypes\ConnectionState::ALERT,
						],
					),
				);

				try {
					$this->getDeviceClient($device)?->disconnect();
				} catch (Throwable) {
					// Just ignore
				}

				$this->logger->error(
					'Device is in invalid state and could not be handled',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'television-client',
						'exception' => ApplicationHelpers\Logger::buildException($ex),
						'connector' => [
							'id' => $this->connector->getId()->toString(),
						],
						'device' => [
							'id' => $device->getId()->toString(),
						],
					],
				);

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
						$this->messageBuilder->create(
							Queue\Messages\StoreChannelPropertyState::class,
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
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'television-client',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
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
							$this->messageBuilder->create(
								Queue\Messages\StoreDeviceConnectionState::class,
								[
									'connector' => $device->getConnector(),
									'device' => $device->getId(),
									'state' => DevicesTypes\ConnectionState::ALERT,
								],
							),
						);
					} elseif ($ex->getCode() === 500) {
						$this->queue->append(
							$this->messageBuilder->create(
								Queue\Messages\StoreDeviceConnectionState::class,
								[
									'connector' => $device->getConnector(),
									'device' => $device->getId(),
									'state' => DevicesTypes\ConnectionState::DISCONNECTED,
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
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function createDeviceClient(Documents\Devices\Device $device): void
	{
		unset($this->processedChannelsProperties[$device->getId()->toString()]);

		assert(is_string($this->deviceHelper->getIpAddress($device)));

		$client = $this->connectionManager->getConnection($device);

		$client->onMessage[] = function (API\Messages\Message $message) use ($device): void {
			if (
				$message instanceof API\Messages\Response\Event
				&& $message->getScreenState() !== null
			) {
				$this->queue->append(
					$this->messageBuilder->create(
						Queue\Messages\StoreChannelPropertyState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'channel' => Types\ChannelType::TELEVISION,
							'property' => Types\ChannelPropertyIdentifier::STATE,
							'value' => $message->getScreenState(),
						],
					),
				);
			}
		};

		$client->onError[] = function (Throwable $ex) use ($device): void {
			$this->logger->warning(
				'Event subscription with device failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'television-client',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $this->connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
				],
			);

			$this->queue->append(
				$this->messageBuilder->create(
					Queue\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $device->getConnector(),
						'device' => $device->getId(),
						'state' => DevicesTypes\ConnectionState::DISCONNECTED,
					],
				),
			);
		};

		$this->devicesClients[$device->getId()->toString()] = $client;
	}

	private function getDeviceClient(Documents\Devices\Device $device): API\TelevisionApi|null
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
			async(function (): void {
				$this->handleCommunication();
			}),
		);
	}

}
