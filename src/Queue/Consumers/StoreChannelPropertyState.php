<?php declare(strict_types = 1);

/**
 * StoreChannelPropertyState.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 * @since          1.0.0
 *
 * @date           01.07.23
 */

namespace FastyBird\Connector\Viera\Queue\Consumers;

use BackedEnum;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\Documents;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Library\Tools\Exceptions as ToolsExceptions;
use FastyBird\Module\Devices\Documents as DevicesDocuments;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\States as DevicesStates;
use Nette;
use Nette\Utils;
use Ramsey\Uuid;
use function array_merge;
use function React\Async\await;

/**
 * Store channel property state message consumer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class StoreChannelPropertyState implements Queue\Consumer
{

	use Nette\SmartObject;

	public function __construct(
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Configuration\Devices\Repository $devicesConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Repository $channelsConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Properties\Repository $channelsPropertiesConfigurationRepository,
		private readonly DevicesModels\States\Async\ChannelPropertiesManager $channelPropertiesStatesManager,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws ToolsExceptions\InvalidArgument
	 */
	public function consume(Queue\Messages\Message $message): bool
	{
		if (!$message instanceof Queue\Messages\StoreChannelPropertyState) {
			return false;
		}

		$findDeviceQuery = new Queries\Configuration\FindDevices();
		$findDeviceQuery->byConnectorId($message->getConnector());
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
					'type' => 'store-channel-property-state-message-consumer',
					'connector' => [
						'id' => $message->getConnector()->toString(),
					],
					'device' => [
						'id' => $message->getDevice()->toString(),
					],
					'channel' => array_merge(
						$message->getChannel() instanceof BackedEnum ? ['identifier' => $message->getChannel()->value] : [],
						!$message->getChannel() instanceof BackedEnum ? ['id' => $message->getChannel()->toString()] : [],
					),
					'property' => array_merge(
						$message->getProperty() instanceof BackedEnum ? ['identifier' => $message->getProperty()->value] : [],
						!$message->getProperty() instanceof BackedEnum ? ['id' => $message->getProperty()->toString()] : [],
					),
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$findChannelQuery = new Queries\Configuration\FindChannels();
		$findChannelQuery->forDevice($device);

		if ($message->getChannel() instanceof Uuid\UuidInterface) {
			$findChannelQuery->byId($message->getChannel());
		} else {
			$findChannelQuery->byIdentifier($message->getChannel());
		}

		$channel = $this->channelsConfigurationRepository->findOneBy(
			$findChannelQuery,
			Documents\Channels\Channel::class,
		);

		if ($channel === null) {
			$this->logger->error(
				'Channel could not be loaded',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'store-channel-property-state-message-consumer',
					'connector' => [
						'id' => $message->getConnector()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => array_merge(
						$message->getChannel() instanceof BackedEnum ? ['identifier' => $message->getChannel()->value] : [],
						!$message->getChannel() instanceof BackedEnum ? ['id' => $message->getChannel()->toString()] : [],
					),
					'property' => array_merge(
						$message->getProperty() instanceof BackedEnum ? ['identifier' => $message->getProperty()->value] : [],
						!$message->getProperty() instanceof BackedEnum ? ['id' => $message->getProperty()->toString()] : [],
					),
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		$findChannelPropertyQuery = new DevicesQueries\Configuration\FindChannelDynamicProperties();
		$findChannelPropertyQuery->forChannel($channel);
		if ($message->getProperty() instanceof Uuid\UuidInterface) {
			$findChannelPropertyQuery->byId($message->getProperty());
		} else {
			$findChannelPropertyQuery->byIdentifier($message->getProperty()->value);
		}

		$property = $this->channelsPropertiesConfigurationRepository->findOneBy(
			$findChannelPropertyQuery,
			DevicesDocuments\Channels\Properties\Dynamic::class,
		);

		if ($property === null) {
			$this->logger->error(
				'Channel property could not be loaded',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'store-channel-property-state-message-consumer',
					'connector' => [
						'id' => $message->getConnector()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'identifier' => $message->getProperty(),
					],
					'data' => $message->toArray(),
				],
			);

			return true;
		}

		if ($property->getDataType() === MetadataTypes\DataType::BUTTON) {
			await($this->channelPropertiesStatesManager->set(
				$property,
				Utils\ArrayHash::from([
					DevicesStates\Property::ACTUAL_VALUE_FIELD => null,
					DevicesStates\Property::VALID_FIELD => true,
				]),
				MetadataTypes\Sources\Connector::VIERA,
			));
			await($this->channelPropertiesStatesManager->setPendingState(
				$property,
				false,
				MetadataTypes\Sources\Connector::VIERA,
			));
		} else {
			await($this->channelPropertiesStatesManager->set(
				$property,
				Utils\ArrayHash::from([
					DevicesStates\Property::ACTUAL_VALUE_FIELD => $message->getValue(),
				]),
				MetadataTypes\Sources\Connector::VIERA,
			));
		}

		$this->logger->debug(
			'Consumed store device state message',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'store-channel-property-state-message-consumer',
				'connector' => [
					'id' => $message->getConnector()->toString(),
				],
				'device' => [
					'id' => $device->getId()->toString(),
				],
				'channel' => array_merge(
					$message->getChannel() instanceof BackedEnum ? ['identifier' => $message->getChannel()->value] : [],
					!$message->getChannel() instanceof BackedEnum ? ['id' => $message->getChannel()->toString()] : [],
				),
				'property' => array_merge(
					$message->getProperty() instanceof BackedEnum ? ['identifier' => $message->getProperty()->value] : [],
					!$message->getProperty() instanceof BackedEnum ? ['id' => $message->getProperty()->toString()] : [],
				),
				'data' => $message->toArray(),
			],
		);

		return true;
	}

}
