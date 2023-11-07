<?php declare(strict_types = 1);

/**
 * WriteChannelPropertyState.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 * @since          1.0.0
 *
 * @date           18.07.23
 */

namespace FastyBird\Connector\Viera\Queue\Consumers;

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
use FastyBird\Module\Devices\States as DevicesStates;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use Nette\Utils;
use RuntimeException;
use Throwable;
use function boolval;
use function intval;
use function strval;

/**
 * Write state to device message consumer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class WriteChannelPropertyState implements Queue\Consumer
{

	use Nette\SmartObject;

	public function __construct(
		private readonly Queue\Queue $queue,
		private readonly API\ConnectionManager $connectionManager,
		private readonly Helpers\Entity $entityHelper,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Entities\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Entities\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesUtilities\ChannelPropertiesStates $channelPropertiesStates,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	public function consume(Entities\Messages\Entity $entity): bool
	{
		if (!$entity instanceof Entities\Messages\WriteChannelPropertyState) {
			return false;
		}

		$findConnectorQuery = new Queries\Entities\FindConnectors();
		$findConnectorQuery->byId($entity->getConnector());

		$connector = $this->connectorsRepository->findOneBy($findConnectorQuery, Entities\VieraConnector::class);

		if ($connector === null) {
			$this->logger->error(
				'Connector could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $entity->getConnector()->toString(),
					],
					'device' => [
						'id' => $entity->getDevice()->toString(),
					],
					'channel' => [
						'id' => $entity->getChannel()->toString(),
					],
					'property' => [
						'id' => $entity->getProperty()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$findDeviceQuery = new Queries\Entities\FindDevices();
		$findDeviceQuery->forConnector($connector);
		$findDeviceQuery->byId($entity->getDevice());

		$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);

		if ($device === null) {
			$this->logger->error(
				'Device could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $entity->getDevice()->toString(),
					],
					'channel' => [
						'id' => $entity->getChannel()->toString(),
					],
					'property' => [
						'id' => $entity->getProperty()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		if ($device->getIpAddress() === null) {
			$this->queue->append(
				$this->entityHelper->create(
					Entities\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId()->toString(),
						'device' => $device->getId()->toString(),
						'state' => MetadataTypes\ConnectionState::STATE_ALERT,
					],
				),
			);

			$this->logger->error(
				'Device is not configured',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $entity->getChannel()->toString(),
					],
					'property' => [
						'id' => $entity->getProperty()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$findChannelQuery = new DevicesQueries\Entities\FindChannels();
		$findChannelQuery->forDevice($device);
		$findChannelQuery->byId($entity->getChannel());

		$channel = $this->channelsRepository->findOneBy($findChannelQuery);

		if ($channel === null) {
			$this->logger->error(
				'Channel could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $entity->getChannel()->toString(),
					],
					'property' => [
						'id' => $entity->getProperty()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$findChannelPropertyQuery = new DevicesQueries\Entities\FindChannelDynamicProperties();
		$findChannelPropertyQuery->forChannel($channel);
		$findChannelPropertyQuery->byId($entity->getProperty());

		$property = $this->channelsPropertiesRepository->findOneBy(
			$findChannelPropertyQuery,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		if ($property === null) {
			$this->logger->error(
				'Channel property could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'id' => $entity->getProperty()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		if (!$property->isSettable()) {
			$this->logger->error(
				'Channel property is not writable',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$state = $this->channelPropertiesStates->getValue($property);

		if ($state === null) {
			return true;
		}

		$expectedValue = DevicesUtilities\ValueHelper::flattenValue($state->getExpectedValue());

		if ($expectedValue === null) {
			return true;
		}

		$valueToWrite = DevicesUtilities\ValueHelper::transformValueToDevice(
			$property->getDataType(),
			$property->getFormat(),
			$expectedValue,
		);

		if ($valueToWrite === null) {
			return true;
		}

		try {
			$client = $this->connectionManager->getConnection($device);

			if (!$client->isConnected()) {
				$client->connect();
			}

			switch ($property->getIdentifier()) {
				case Types\ChannelPropertyIdentifier::STATE:
					$result = $valueToWrite === true ? $client->turnOn() : $client->turnOff();

					break;
				case Types\ChannelPropertyIdentifier::VOLUME:
					$result = $client->setVolume(intval($valueToWrite));

					break;
				case Types\ChannelPropertyIdentifier::MUTE:
					$result = $client->setMute(boolval($valueToWrite));

					break;
				case Types\ChannelPropertyIdentifier::INPUT_SOURCE:
					if (intval($valueToWrite) < 100) {
						$result = $client->sendKey('NRC_HDMI' . $valueToWrite . '-ONOFF');
					} elseif (intval($valueToWrite) === 500) {
						$result = $client->sendKey(Types\ActionKey::get(Types\ActionKey::AD_CHANGE));
					} else {
						$result = $client->launchApplication(strval($valueToWrite));
					}

					break;
				case Types\ChannelPropertyIdentifier::APPLICATION:
					$result = $client->launchApplication(strval($valueToWrite));

					break;
				case Types\ChannelPropertyIdentifier::HDMI:
					$result = $client->sendKey('NRC_HDMI' . $valueToWrite . '-ONOFF');

					break;
				default:
					if (
						Types\ChannelPropertyIdentifier::isValidValue($property->getIdentifier())
						&& $property->getDataType()->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)
					) {
						$result = $client->sendKey(Types\ActionKey::get($valueToWrite));
					} else {
						$this->logger->error(
							'Provided property is not supported for writing',
							[
								'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
								'type' => 'write-channel-property-state-message-consumer',
								'connector' => [
									'id' => $connector->getId()->toString(),
								],
								'device' => [
									'id' => $device->getId()->toString(),
								],
								'channel' => [
									'id' => $channel->getId()->toString(),
								],
								'property' => [
									'id' => $property->getId()->toString(),
								],
								'data' => $entity->toArray(),
							],
						);

						return true;
					}

					break;
			}
		} catch (Exceptions\InvalidState $ex) {
			$this->queue->append(
				$this->entityHelper->create(
					Entities\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId()->toString(),
						'device' => $device->getId()->toString(),
						'state' => MetadataTypes\ConnectionState::STATE_ALERT,
					],
				),
			);

			$this->logger->error(
				'Device is not properly configured',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		} catch (Exceptions\TelevisionApiError $ex) {
			$this->queue->append(
				$this->entityHelper->create(
					Entities\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId()->toString(),
						'device' => $device->getId()->toString(),
						'state' => MetadataTypes\ConnectionState::STATE_ALERT,
					],
				),
			);

			$this->logger->error(
				'Preparing api request failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$this->queue->append(
				$this->entityHelper->create(
					Entities\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId()->toString(),
						'device' => $device->getId()->toString(),
						'state' => MetadataTypes\ConnectionState::STATE_DISCONNECTED,
					],
				),
			);

			$this->logger->error(
				'Calling device api failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'write-channel-property-state-message-consumer',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
					],
					'request' => [
						'method' => $ex->getRequest()?->getMethod(),
						'url' => $ex->getRequest() !== null ? strval($ex->getRequest()->getUri()) : null,
						'body' => $ex->getRequest()?->getBody()->getContents(),
					],
					'response' => [
						'body' => $ex->getResponse()?->getBody()->getContents(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$result->then(
			function () use ($connector, $device, $property, $valueToWrite): void {
				$now = $this->dateTimeFactory->getNow();

				$state = $this->channelPropertiesStates->getValue($property);

				if ($state?->getExpectedValue() !== null) {
					$this->channelPropertiesStates->setValue(
						$property,
						Utils\ArrayHash::from([
							DevicesStates\Property::PENDING_KEY => $now->format(DateTimeInterface::ATOM),
						]),
					);
				}

				switch ($property->getIdentifier()) {
					case Types\ChannelPropertyIdentifier::STATE:
					case Types\ChannelPropertyIdentifier::VOLUME:
					case Types\ChannelPropertyIdentifier::MUTE:
					case Types\ChannelPropertyIdentifier::INPUT_SOURCE:
					case Types\ChannelPropertyIdentifier::APPLICATION:
					case Types\ChannelPropertyIdentifier::HDMI:
						$this->queue->append(
							$this->entityHelper->create(
								Entities\Messages\StoreChannelPropertyState::class,
								[
									'connector' => $connector->getId()->toString(),
									'device' => $device->getId()->toString(),
									'channel' => Types\ChannelType::TELEVISION,
									'property' => $property->getIdentifier(),
									'value' => $valueToWrite,
								],
							),
						);

						break;
					default:
						if (
							Types\ChannelPropertyIdentifier::isValidValue($property->getIdentifier())
							&& $property->getDataType()->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)
						) {
							$this->queue->append(
								$this->entityHelper->create(
									Entities\Messages\StoreChannelPropertyState::class,
									[
										'connector' => $connector->getId()->toString(),
										'device' => $device->getId()->toString(),
										'channel' => Types\ChannelType::TELEVISION,
										'property' => $property->getIdentifier(),
										'value' => $valueToWrite,
									],
								),
							);
						}

						break;
				}

				if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::INPUT_SOURCE) {
					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $connector->getId()->toString(),
								'device' => $device->getId()->toString(),
								'channel' => Types\ChannelType::TELEVISION,
								'property' => Types\ChannelPropertyIdentifier::HDMI,
								'value' => intval($valueToWrite) < 100 ? $valueToWrite : null,
							],
						),
					);

					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $connector->getId()->toString(),
								'device' => $device->getId()->toString(),
								'channel' => Types\ChannelType::TELEVISION,
								'property' => Types\ChannelPropertyIdentifier::APPLICATION,
								'value' => intval($valueToWrite) !== 500 ? $valueToWrite : null,
							],
						),
					);
				}

				if (
					$property->getIdentifier() === Types\ChannelPropertyIdentifier::APPLICATION
					|| $property->getIdentifier() === Types\ChannelPropertyIdentifier::HDMI
				) {
					if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::HDMI) {
						$this->queue->append(
							$this->entityHelper->create(
								Entities\Messages\StoreChannelPropertyState::class,
								[
									'connector' => $connector->getId()->toString(),
									'device' => $device->getId()->toString(),
									'channel' => Types\ChannelType::TELEVISION,
									'property' => Types\ChannelPropertyIdentifier::APPLICATION,
									'value' => null,
								],
							),
						);
					}

					if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::APPLICATION) {
						$this->queue->append(
							$this->entityHelper->create(
								Entities\Messages\StoreChannelPropertyState::class,
								[
									'connector' => $connector->getId()->toString(),
									'device' => $device->getId()->toString(),
									'channel' => Types\ChannelType::TELEVISION,
									'property' => Types\ChannelPropertyIdentifier::HDMI,
									'value' => null,
								],
							),
						);
					}

					$this->queue->append(
						$this->entityHelper->create(
							Entities\Messages\StoreChannelPropertyState::class,
							[
								'connector' => $connector->getId()->toString(),
								'device' => $device->getId()->toString(),
								'channel' => Types\ChannelType::TELEVISION,
								'property' => Types\ChannelPropertyIdentifier::INPUT_SOURCE,
								'value' => $valueToWrite,
							],
						),
					);
				}
			},
			function (Throwable $ex) use ($connector, $device, $property): void {
				$this->channelPropertiesStates->setValue(
					$property,
					Utils\ArrayHash::from([
						DevicesStates\Property::EXPECTED_VALUE_KEY => null,
						DevicesStates\Property::PENDING_KEY => false,
					]),
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
								'connector' => $connector->getId()->toString(),
								'device' => $device->getId()->toString(),
								'state' => MetadataTypes\ConnectionState::STATE_DISCONNECTED,
							],
						),
					);
				}
			},
		);

		$this->logger->debug(
			'Consumed write sub device state message',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'write-channel-property-state-message-consumer',
				'connector' => [
					'id' => $connector->getId()->toString(),
				],
				'device' => [
					'id' => $device->getId()->toString(),
				],
				'channel' => [
					'id' => $channel->getId()->toString(),
				],
				'property' => [
					'id' => $property->getId()->toString(),
				],
				'data' => $entity->toArray(),
			],
		);

		return true;
	}

}
