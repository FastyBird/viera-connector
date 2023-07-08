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
use Exception;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Consumers;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Types;
use FastyBird\Connector\Viera\Writers;
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
use Psr\Log;
use React\EventLoop;
use React\Promise;
use RuntimeException;
use Throwable;
use function array_key_exists;
use function assert;
use function boolval;
use function in_array;
use function intval;
use function is_string;
use function strval;

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

	private Log\LoggerInterface $logger;

	public function __construct(
		private readonly Entities\VieraConnector $connector,
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesUtilities\DeviceConnection $deviceConnectionManager,
		private readonly DevicesUtilities\ChannelPropertiesStates $channelPropertiesStates,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
		private readonly Consumers\Messages $consumer,
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly Writers\Writer $writer,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelPropertiesRepository,
		Log\LoggerInterface|null $logger = null,
	)
	{
		$this->logger = $logger ?? new Log\NullLogger();
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	public function connect(): void
	{
		$this->processedDevices = [];

		$findDevicesQuery = new DevicesQueries\FindDevices();
		$findDevicesQuery->forConnector($this->connector);

		foreach ($this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class) as $device) {
			assert($device instanceof Entities\VieraDevice);

			$this->createDeviceClient($device);
		}

		$this->eventLoop->addTimer(
			self::HANDLER_START_DELAY,
			function (): void {
				$this->registerLoopHandler();
			},
		);

		$this->writer->connect($this->connector, $this);
	}

	public function disconnect(): void
	{
		foreach ($this->devicesClients as $client) {
			$client->disconnect();
		}

		if ($this->handlerTimer !== null) {
			$this->eventLoop->cancelTimer($this->handlerTimer);

			$this->handlerTimer = null;
		}

		$this->writer->disconnect($this->connector, $this);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	public function writeChannelProperty(
		Entities\VieraDevice $device,
		DevicesEntities\Channels\Channel $channel,
		DevicesEntities\Channels\Properties\Dynamic $property,
	): Promise\PromiseInterface
	{
		$client = $this->getDeviceClient($device);

		if ($client === null) {
			return Promise\reject(new Exceptions\InvalidArgument('For provided device is not created client'));
		}

		$state = $this->channelPropertiesStates->getValue($property);

		if ($state === null) {
			return Promise\reject(
				new Exceptions\InvalidArgument('Property state could not be found. Nothing to write'),
			);
		}

		if (!$property->isSettable()) {
			return Promise\reject(new Exceptions\InvalidArgument('Provided property is not writable'));
		}

		if ($state->getExpectedValue() === null) {
			return Promise\reject(
				new Exceptions\InvalidArgument('Property expected value is not set. Nothing to write'),
			);
		}

		$valueToWrite = API\Transformer::transformValueToDevice(
			$property->getDataType(),
			$property->getFormat(),
			$state->getExpectedValue(),
		);

		if ($valueToWrite === null) {
			return Promise\reject(
				new Exceptions\InvalidArgument('Property expected value could not be transformed to device'),
			);
		}

		if ($state->isPending() === true) {
			switch ($property->getIdentifier()) {
				case Types\ChannelPropertyIdentifier::IDENTIFIER_STATE:
					$result = $valueToWrite === true ? $client->turnOn() : $client->turnOff();

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_VOLUME:
					$result = $client->setVolume(intval($valueToWrite));

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_MUTE:
					$result = $client->setMute(boolval($valueToWrite));

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_INPUT_SOURCE:
					if (intval($valueToWrite) < 100) {
						$result = $client->sendKey('NRC_HDMI' . $valueToWrite . '-ONOFF');
					} elseif (intval($valueToWrite) === 500) {
						$result = $client->sendKey(Types\ActionKey::get(Types\ActionKey::AD_CHANGE));
					} else {
						$result = $client->launchApplication(strval($valueToWrite));
					}

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION:
					$result = $client->launchApplication(strval($valueToWrite));

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI:
					$result = $client->sendKey('NRC_HDMI' . $valueToWrite . '-ONOFF');

					break;
				default:
					if (
						Types\ChannelPropertyIdentifier::isValidValue($property->getIdentifier())
						&& $property->getDataType()->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)
					) {
						$result = $client->sendKey(Types\ActionKey::get($valueToWrite));
					} else {
						return Promise\reject(
							new Exceptions\InvalidArgument('Provided property is not supported for writing'),
						);
					}

					break;
			}

			$deferred = new Promise\Deferred();

			$result->then(
				function () use ($deferred, $device, $property, $state, $valueToWrite): void {
					switch ($property->getIdentifier()) {
						case Types\ChannelPropertyIdentifier::IDENTIFIER_STATE:
						case Types\ChannelPropertyIdentifier::IDENTIFIER_VOLUME:
						case Types\ChannelPropertyIdentifier::IDENTIFIER_MUTE:
						case Types\ChannelPropertyIdentifier::IDENTIFIER_INPUT_SOURCE:
						case Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION:
						case Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI:
							$this->consumer->append(
								new Entities\Messages\ChannelPropertyState(
									$this->connector->getId(),
									$device->getIdentifier(),
									Types\ChannelType::TELEVISION,
									$property->getIdentifier(),
									$state->getExpectedValue(),
								),
							);

							break;
						default:
							if (
								Types\ChannelPropertyIdentifier::isValidValue($property->getIdentifier())
								&& $property->getDataType()->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)
							) {
								$this->consumer->append(
									new Entities\Messages\ChannelPropertyState(
										$this->connector->getId(),
										$device->getIdentifier(),
										Types\ChannelType::TELEVISION,
										$property->getIdentifier(),
										$state->getExpectedValue(),
									),
								);
							}

							break;
					}

					if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::IDENTIFIER_INPUT_SOURCE) {
						$this->consumer->append(
							new Entities\Messages\ChannelPropertyState(
								$this->connector->getId(),
								$device->getIdentifier(),
								Types\ChannelType::TELEVISION,
								Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI,
								null,
							),
						);

						$this->consumer->append(
							new Entities\Messages\ChannelPropertyState(
								$this->connector->getId(),
								$device->getIdentifier(),
								Types\ChannelType::TELEVISION,
								Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION,
								null,
							),
						);

						if (intval($valueToWrite) < 100) {
							$this->consumer->append(
								new Entities\Messages\ChannelPropertyState(
									$this->connector->getId(),
									$device->getIdentifier(),
									Types\ChannelType::TELEVISION,
									Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI,
									$state->getExpectedValue(),
								),
							);
						} elseif (intval($valueToWrite) !== 500) {
							$this->consumer->append(
								new Entities\Messages\ChannelPropertyState(
									$this->connector->getId(),
									$device->getIdentifier(),
									Types\ChannelType::TELEVISION,
									Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION,
									$state->getExpectedValue(),
								),
							);
						}
					}

					if (
						$property->getIdentifier() === Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION
						|| $property->getIdentifier() === Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI
					) {
						if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI) {
							$this->consumer->append(
								new Entities\Messages\ChannelPropertyState(
									$this->connector->getId(),
									$device->getIdentifier(),
									Types\ChannelType::TELEVISION,
									Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION,
									null,
								),
							);
						}

						if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION) {
							$this->consumer->append(
								new Entities\Messages\ChannelPropertyState(
									$this->connector->getId(),
									$device->getIdentifier(),
									Types\ChannelType::TELEVISION,
									Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI,
									null,
								),
							);
						}

						$this->consumer->append(
							new Entities\Messages\ChannelPropertyState(
								$this->connector->getId(),
								$device->getIdentifier(),
								Types\ChannelType::TELEVISION,
								Types\ChannelPropertyIdentifier::IDENTIFIER_INPUT_SOURCE,
								$state->getExpectedValue(),
							),
						);
					}

					$deferred->resolve();
				},
				function (Throwable $ex) use ($deferred, $device): void {
					if ($ex->getCode() === 500) {
						$this->consumer->append(
							new Entities\Messages\DeviceState(
								$device->getConnector()->getId(),
								$device->getIdentifier(),
								MetadataTypes\ConnectionState::get(MetadataTypes\ConnectionState::STATE_DISCONNECTED),
							),
						);
					}

					$deferred->reject($ex);
				},
			);

			return $deferred->promise();
		}

		return Promise\reject(new Exceptions\InvalidArgument('Provided property state is in invalid state'));
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws Exception
	 */
	private function handleCommunication(): void
	{
		$findDevicesQuery = new DevicesQueries\FindDevices();
		$findDevicesQuery->forConnector($this->connector);

		foreach ($this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class) as $device) {
			assert($device instanceof Entities\VieraDevice);

			if (
				!in_array($device->getPlainId(), $this->processedDevices, true)
				&& !$this->deviceConnectionManager->getState($device)->equalsValue(
					MetadataTypes\ConnectionState::STATE_STOPPED,
				)
			) {
				$this->processedDevices[] = $device->getPlainId();

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
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	private function processDevice(Entities\VieraDevice $device): bool
	{
		$client = $this->getDeviceClient($device);

		if ($client === null) {
			$this->createDeviceClient($device);

			return false;
		}

		if (!$client->isConnected()) {
			$client->connect(true);

			$this->consumer->append(
				new Entities\Messages\DeviceState(
					$device->getConnector()->getId(),
					$device->getIdentifier(),
					MetadataTypes\ConnectionState::get(MetadataTypes\ConnectionState::STATE_CONNECTED),
				),
			);
		}

		$findChannelsQuery = new DevicesQueries\FindChannels();
		$findChannelsQuery->forDevice($device);
		$findChannelsQuery->byIdentifier(Types\ChannelType::TELEVISION);

		$channel = $this->channelsRepository->findOneBy($findChannelsQuery);

		if ($channel === null) {
			$this->consumer->append(
				new Entities\Messages\DeviceState(
					$device->getConnector()->getId(),
					$device->getIdentifier(),
					MetadataTypes\ConnectionState::get(MetadataTypes\ConnectionState::STATE_STOPPED),
				),
			);

			return false;
		}

		$findChannelPropertiesQuery = new DevicesQueries\FindChannelProperties();
		$findChannelPropertiesQuery->forChannel($channel);

		foreach ($this->channelPropertiesRepository->findAllBy($findChannelPropertiesQuery) as $property) {
			if (!$property instanceof DevicesEntities\Channels\Properties\Dynamic) {
				continue;
			}

			if (!array_key_exists($device->getIdentifier(), $this->processedChannelsProperties)) {
				$this->processedChannelsProperties[$device->getIdentifier()] = [];
			}

			if (array_key_exists(
				$property->getIdentifier(),
				$this->processedChannelsProperties[$device->getIdentifier()],
			)) {
				$cmdResult = $this->processedChannelsProperties[$device->getIdentifier()][$property->getIdentifier()];

				if (
					$cmdResult instanceof DateTimeInterface
					&& (
						$this->dateTimeFactory->getNow()->getTimestamp() - $cmdResult->getTimestamp() < $device->getStatusReadingDelay()
					)
				) {
					return false;
				}
			}

			$this->processedChannelsProperties[$device->getIdentifier()][$property->getIdentifier()] = $this->dateTimeFactory->getNow();

			$result = null;

			switch ($property->getIdentifier()) {
				case Types\ChannelPropertyIdentifier::IDENTIFIER_STATE:
					$result = $client->isTurnedOn();

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_VOLUME:
					$result = $client->getVolume();

					break;
				case Types\ChannelPropertyIdentifier::IDENTIFIER_MUTE:
					$result = $client->getMute();

					break;
			}

			if ($result === null) {
				continue;
			}

			$result
				->then(function (int|bool $value) use ($device, $channel, $property): void {
					$this->processedChannelsProperties[$device->getIdentifier()][$property->getIdentifier()] = $this->dateTimeFactory->getNow();

					$this->consumer->append(new Entities\Messages\ChannelPropertyState(
						$this->connector->getId(),
						$device->getIdentifier(),
						$channel->getIdentifier(),
						$property->getIdentifier(),
						API\Transformer::transformValueFromDevice(
							$property->getDataType(),
							$property->getFormat(),
							$value,
						),
					));
				})
				->otherwise(function (Throwable $ex) use ($device, $property): void {
					$this->processedChannelsProperties[$device->getIdentifier()][$property->getIdentifier()] = false;

					$this->logger->warning(
						'Could not call local api',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'local-client',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
							'connector' => [
								'id' => $this->connector->getPlainId(),
							],
							'device' => [
								'id' => $device->getPlainId(),
							],
						],
					);

					if ($ex->getCode() === 500) {
						$this->consumer->append(
							new Entities\Messages\DeviceState(
								$device->getConnector()->getId(),
								$device->getIdentifier(),
								MetadataTypes\ConnectionState::get(MetadataTypes\ConnectionState::STATE_DISCONNECTED),
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
		unset($this->processedChannelsProperties[$device->getIdentifier()]);

		assert(is_string($device->getIpAddress()));

		$client = $this->televisionApiFactory->create(
			$device->getIdentifier(),
			$device->getIpAddress(),
			$device->getPort(),
			$device->getAppId(),
			$device->getEncryptionKey(),
			$device->getMacAddress(),
		);

		$client->on(
			'event-data',
			function (Entities\API\Event $event) use ($device): void {
				if ($event->getScreenState() !== null) {
					$this->consumer->append(
						new Entities\Messages\ChannelPropertyState(
							$this->connector->getId(),
							$device->getIdentifier(),
							Types\ChannelType::TELEVISION,
							Types\ChannelPropertyIdentifier::IDENTIFIER_STATE,
							$event->getScreenState(),
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
						'type' => 'local-client',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
						'connector' => [
							'id' => $this->connector->getPlainId(),
						],
						'device' => [
							'id' => $device->getPlainId(),
						],
					],
				);

				$this->consumer->append(
					new Entities\Messages\DeviceState(
						$device->getConnector()->getId(),
						$device->getIdentifier(),
						MetadataTypes\ConnectionState::get(MetadataTypes\ConnectionState::STATE_DISCONNECTED),
					),
				);
			},
		);

		$this->devicesClients[$device->getPlainId()] = $client;
	}

	private function getDeviceClient(Entities\VieraDevice $device): API\TelevisionApi|null
	{
		return array_key_exists(
			$device->getPlainId(),
			$this->devicesClients,
		)
			? $this->devicesClients[$device->getPlainId()]
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
