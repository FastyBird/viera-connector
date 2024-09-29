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
use FastyBird\Library\Metadata\Utilities as MetadataUtilities;
use FastyBird\Module\Devices\Documents as DevicesDocuments;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\States as DevicesStates;
use FastyBird\Module\Devices\Types as DevicesTypes;
use Nette;
use Nette\Utils;
use RuntimeException;
use Throwable;
use TypeError;
use ValueError;
use function assert;
use function boolval;
use function intval;
use function React\Async\async;
use function React\Async\await;
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

	private const WRITE_PENDING_DELAY = 2_000.0;

	public function __construct(
		private readonly Queue\Queue $queue,
		private readonly API\ConnectionManager $connectionManager,
		private readonly Helpers\MessageBuilder $messageBuilder,
		private readonly Helpers\Device $deviceHelper,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Configuration\Connectors\Repository $connectorsConfigurationRepository,
		private readonly DevicesModels\Configuration\Devices\Repository $devicesConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Repository $channelsConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Properties\Repository $channelsPropertiesConfigurationRepository,
		private readonly DevicesModels\States\Async\ChannelPropertiesManager $channelPropertiesStatesManager,
		private readonly DateTimeFactory\Clock $clock,
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
	 * @throws Throwable
	 * @throws TypeError
	 * @throws ValueError
	 */
	public function consume(Queue\Messages\Message $message): bool
	{
		if (!$message instanceof Queue\Messages\WriteChannelPropertyState) {
			return false;
		}

		$findConnectorQuery = new Queries\Configuration\FindConnectors();
		$findConnectorQuery->byId($message->getConnector());

		$connector = $this->connectorsConfigurationRepository->findOneBy(
			$findConnectorQuery,
			Documents\Connectors\Connector::class,
		);

		if ($connector === null) {
			$this->logger->error(
				'Connector could not be loaded',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $message->getConnector()->toString(),
					],
					'device' => [
						'id' => $message->getDevice()->toString(),
					],
					'channel' => [
						'id' => $message->getChannel()->toString(),
					],
					'property' => [
						'id' => $message->getProperty()->toString(),
					],
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$findDeviceQuery = new Queries\Configuration\FindDevices();
		$findDeviceQuery->forConnector($connector);
		$findDeviceQuery->byId($message->getDevice());

		$device = $this->devicesConfigurationRepository->findOneBy(
			$findDeviceQuery,
			Documents\Devices\Device::class,
		);

		if ($device === null) {
			$this->logger->error(
				'Device could not be loaded',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $message->getDevice()->toString(),
					],
					'channel' => [
						'id' => $message->getChannel()->toString(),
					],
					'property' => [
						'id' => $message->getProperty()->toString(),
					],
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		if ($this->deviceHelper->getIpAddress($device) === null) {
			$this->queue->append(
				$this->messageBuilder->create(
					Queue\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId(),
						'device' => $device->getId(),
						'state' => DevicesTypes\ConnectionState::ALERT,
					],
				),
			);

			$this->logger->error(
				'Device is not configured',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $message->getChannel()->toString(),
					],
					'property' => [
						'id' => $message->getProperty()->toString(),
					],
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$findChannelQuery = new Queries\Configuration\FindChannels();
		$findChannelQuery->forDevice($device);
		$findChannelQuery->byId($message->getChannel());

		$channel = $this->channelsConfigurationRepository->findOneBy(
			$findChannelQuery,
			Documents\Channels\Channel::class,
		);

		if ($channel === null) {
			$this->logger->error(
				'Channel could not be loaded',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'connector' => [
						'id' => $connector->getId()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $message->getChannel()->toString(),
					],
					'property' => [
						'id' => $message->getProperty()->toString(),
					],
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$findChannelPropertyQuery = new DevicesQueries\Configuration\FindChannelDynamicProperties();
		$findChannelPropertyQuery->forChannel($channel);
		$findChannelPropertyQuery->byId($message->getProperty());

		$property = $this->channelsPropertiesConfigurationRepository->findOneBy(
			$findChannelPropertyQuery,
			DevicesDocuments\Channels\Properties\Dynamic::class,
		);

		if ($property === null) {
			$this->logger->error(
				'Channel property could not be loaded',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
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
						'id' => $message->getProperty()->toString(),
					],
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		if (!$property->isSettable()) {
			$this->logger->warning(
				'Channel property is not writable',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
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
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$state = $message->getState();

		if ($state === null) {
			return true;
		}

		$expectedValue = MetadataUtilities\Value::flattenValue(
			$state->getExpectedValue(),
		);

		if ($expectedValue === null) {
			await($this->channelPropertiesStatesManager->setPendingState(
				$property,
				false,
				MetadataTypes\Sources\Connector::VIERA,
			));

			return true;
		}

		$now = $this->clock->getNow();
		$pending = $state->getPending();

		if (
			$pending === false
			|| (
				$pending instanceof DateTimeInterface
				&& (float) $now->format('Uv') - (float) $pending->format('Uv') <= self::WRITE_PENDING_DELAY
			)
		) {
			return true;
		}

		await($this->channelPropertiesStatesManager->setPendingState(
			$property,
			true,
			MetadataTypes\Sources\Connector::VIERA,
		));

		try {
			$client = $this->connectionManager->getConnection($device);

			if (!$client->isConnected()) {
				$client->connect();
			}

			switch ($property->getIdentifier()) {
				case Types\ChannelPropertyIdentifier::STATE->value:
					$result = $expectedValue === true ? $client->turnOn() : $client->turnOff();

					break;
				case Types\ChannelPropertyIdentifier::VOLUME->value:
					$result = $client->setVolume(intval($expectedValue));

					break;
				case Types\ChannelPropertyIdentifier::MUTE->value:
					$result = $client->setMute(boolval($expectedValue));

					break;
				case Types\ChannelPropertyIdentifier::INPUT_SOURCE->value:
					if (intval($expectedValue) < Viera\Constants::MAX_HDMI_CODE) {
						$result = $client->sendKey('NRC_HDMI' . $expectedValue . '-ONOFF');
					} elseif (intval($expectedValue) === Viera\Constants::TV_CODE) {
						$result = $client->sendKey(Types\ActionKey::AD_CHANGE);
					} else {
						$result = $client->launchApplication(strval($expectedValue));
					}

					break;
				case Types\ChannelPropertyIdentifier::APPLICATION->value:
					$result = $client->launchApplication(strval($expectedValue));

					break;
				case Types\ChannelPropertyIdentifier::HDMI->value:
					$result = $client->sendKey('NRC_HDMI' . $expectedValue . '-ONOFF');

					break;
				case Types\ChannelPropertyIdentifier::REMOTE->value:
					$key = Types\ActionKey::tryFrom(strval($expectedValue));

					if ($key === null) {
						await($this->channelPropertiesStatesManager->setPendingState(
							$property,
							false,
							MetadataTypes\Sources\Connector::VIERA,
						));

						$this->logger->error(
							'Provided property value is not valid',
							[
								'source' => MetadataTypes\Sources\Connector::VIERA->value,
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
								'data' => $message->toArray(),
							],
						);

						return true;
					}

					$result = $client->sendKey($key);

					break;
				default:
					if (
						Types\ChannelPropertyIdentifier::tryFrom($property->getIdentifier()) !== null
						&& $property->getDataType() === MetadataTypes\DataType::BUTTON
					) {
						$result = $client->sendKey(Types\ActionKey::from(strval($expectedValue)));
					} else {
						await($this->channelPropertiesStatesManager->setPendingState(
							$property,
							false,
							MetadataTypes\Sources\Connector::VIERA,
						));

						$this->logger->error(
							'Provided property is not supported for writing',
							[
								'source' => MetadataTypes\Sources\Connector::VIERA->value,
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
								'data' => $message->toArray(),
							],
						);

						return true;
					}

					break;
			}
		} catch (Exceptions\InvalidState $ex) {
			$this->queue->append(
				$this->messageBuilder->create(
					Queue\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId(),
						'device' => $device->getId(),
						'state' => DevicesTypes\ConnectionState::ALERT,
					],
				),
			);

			await($this->channelPropertiesStatesManager->setPendingState(
				$property,
				false,
				MetadataTypes\Sources\Connector::VIERA,
			));

			$this->logger->error(
				'Device is not properly configured',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
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
					'data' => $message->toArray(),
				],
			);

			return true;
		} catch (Exceptions\TelevisionApiError $ex) {
			$this->queue->append(
				$this->messageBuilder->create(
					Queue\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId(),
						'device' => $device->getId(),
						'state' => DevicesTypes\ConnectionState::ALERT,
					],
				),
			);

			await($this->channelPropertiesStatesManager->setPendingState(
				$property,
				false,
				MetadataTypes\Sources\Connector::VIERA,
			));

			$this->logger->error(
				'Preparing api request failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
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
					'data' => $message->toArray(),
				],
			);

			return true;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$this->queue->append(
				$this->messageBuilder->create(
					Queue\Messages\StoreDeviceConnectionState::class,
					[
						'connector' => $connector->getId(),
						'device' => $device->getId(),
						'state' => DevicesTypes\ConnectionState::DISCONNECTED,
					],
				),
			);

			await($this->channelPropertiesStatesManager->setPendingState(
				$property,
				false,
				MetadataTypes\Sources\Connector::VIERA,
			));

			$this->logger->error(
				'Calling device api failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'write-channel-property-state-message-consumer',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
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
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$result->then(
			async(function () use ($message, $connector, $device, $channel, $property, $expectedValue): void {
				$this->logger->debug(
					'Channel state was successfully sent to device',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
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
						'data' => $message->toArray(),
					],
				);

				switch ($property->getIdentifier()) {
					case Types\ChannelPropertyIdentifier::STATE->value:
					case Types\ChannelPropertyIdentifier::VOLUME->value:
					case Types\ChannelPropertyIdentifier::MUTE->value:
					case Types\ChannelPropertyIdentifier::INPUT_SOURCE->value:
					case Types\ChannelPropertyIdentifier::HDMI->value:
					case Types\ChannelPropertyIdentifier::APPLICATION->value:
						await($this->channelPropertiesStatesManager->set(
							$property,
							Utils\ArrayHash::from([
								DevicesStates\Property::ACTUAL_VALUE_FIELD => $expectedValue,
								DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
							]),
							MetadataTypes\Sources\Connector::VIERA,
						));

						break;
					case Types\ChannelPropertyIdentifier::REMOTE->value:
						await($this->channelPropertiesStatesManager->setPendingState(
							$property,
							false,
							MetadataTypes\Sources\Connector::VIERA,
						));

						break;
					default:
						if (
							Types\ChannelPropertyIdentifier::tryFrom($property->getIdentifier()) !== null
							&& $property->getDataType() === MetadataTypes\DataType::BUTTON
						) {
							await($this->channelPropertiesStatesManager->setPendingState(
								$property,
								false,
								MetadataTypes\Sources\Connector::VIERA,
							));
						}

						break;
				}

				if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::INPUT_SOURCE->value) {
					$findChannelPropertyQuery = new Queries\Configuration\FindChannelProperties();
					$findChannelPropertyQuery->forChannel($channel);
					$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

					$applicationProperty = $this->channelsPropertiesConfigurationRepository->findOneBy(
						$findChannelPropertyQuery,
						DevicesDocuments\Channels\Properties\Dynamic::class,
					);
					assert($applicationProperty instanceof DevicesDocuments\Channels\Properties\Dynamic);

					await($this->channelPropertiesStatesManager->set(
						$applicationProperty,
						Utils\ArrayHash::from([
							DevicesStates\Property::ACTUAL_VALUE_FIELD =>
								intval(
									MetadataUtilities\Value::toString($expectedValue, true),
								) > Viera\Constants::MIN_APPLICATION_CODE
									? $expectedValue
									: null,
							DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
						]),
						MetadataTypes\Sources\Connector::VIERA,
					));

					$findChannelPropertyQuery = new Queries\Configuration\FindChannelProperties();
					$findChannelPropertyQuery->forChannel($channel);
					$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

					$hdmiProperty = $this->channelsPropertiesConfigurationRepository->findOneBy(
						$findChannelPropertyQuery,
						DevicesDocuments\Channels\Properties\Dynamic::class,
					);
					assert($hdmiProperty instanceof DevicesDocuments\Channels\Properties\Dynamic);

					await($this->channelPropertiesStatesManager->set(
						$hdmiProperty,
						Utils\ArrayHash::from([
							DevicesStates\Property::ACTUAL_VALUE_FIELD =>
								intval(
									MetadataUtilities\Value::toString($expectedValue, true),
								) < Viera\Constants::MAX_HDMI_CODE
									? $expectedValue
									: null,
							DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
						]),
						MetadataTypes\Sources\Connector::VIERA,
					));

				} elseif (
					$property->getIdentifier() === Types\ChannelPropertyIdentifier::APPLICATION->value
					|| $property->getIdentifier() === Types\ChannelPropertyIdentifier::HDMI->value
				) {
					$findChannelPropertyQuery = new Queries\Configuration\FindChannelProperties();
					$findChannelPropertyQuery->forChannel($channel);
					$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::INPUT_SOURCE);

					$inputSourceProperty = $this->channelsPropertiesConfigurationRepository->findOneBy(
						$findChannelPropertyQuery,
						DevicesDocuments\Channels\Properties\Dynamic::class,
					);
					assert($inputSourceProperty instanceof DevicesDocuments\Channels\Properties\Dynamic);

					await($this->channelPropertiesStatesManager->set(
						$inputSourceProperty,
						Utils\ArrayHash::from([
							DevicesStates\Property::ACTUAL_VALUE_FIELD => $expectedValue,
							DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
						]),
						MetadataTypes\Sources\Connector::VIERA,
					));

					$findChannelPropertyQuery = new Queries\Configuration\FindChannelProperties();
					$findChannelPropertyQuery->forChannel($channel);
					$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

					$hdmiProperty = $this->channelsPropertiesConfigurationRepository->findOneBy(
						$findChannelPropertyQuery,
						DevicesDocuments\Channels\Properties\Dynamic::class,
					);
					assert($hdmiProperty instanceof DevicesDocuments\Channels\Properties\Dynamic);

					$findChannelPropertyQuery = new Queries\Configuration\FindChannelProperties();
					$findChannelPropertyQuery->forChannel($channel);
					$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

					$applicationProperty = $this->channelsPropertiesConfigurationRepository->findOneBy(
						$findChannelPropertyQuery,
						DevicesDocuments\Channels\Properties\Dynamic::class,
					);
					assert($applicationProperty instanceof DevicesDocuments\Channels\Properties\Dynamic);

					if ($property->getIdentifier() === Types\ChannelPropertyIdentifier::APPLICATION->value) {
						await($this->channelPropertiesStatesManager->set(
							$hdmiProperty,
							Utils\ArrayHash::from([
								DevicesStates\Property::ACTUAL_VALUE_FIELD => null,
								DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
							]),
							MetadataTypes\Sources\Connector::VIERA,
						));

					} elseif ($property->getIdentifier() === Types\ChannelPropertyIdentifier::HDMI->value) {
						await($this->channelPropertiesStatesManager->set(
							$applicationProperty,
							Utils\ArrayHash::from([
								DevicesStates\Property::ACTUAL_VALUE_FIELD => null,
								DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
							]),
							MetadataTypes\Sources\Connector::VIERA,
						));
					}
				}
			}),
			async(function (Throwable $ex) use ($device, $property): void {
				await($this->channelPropertiesStatesManager->setPendingState(
					$property,
					false,
					MetadataTypes\Sources\Connector::VIERA,
				));

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
			}),
		);

		$this->logger->debug(
			'Consumed write device state message',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
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
				'data' => $message->toArray(),
			],
		);

		return true;
	}

}
