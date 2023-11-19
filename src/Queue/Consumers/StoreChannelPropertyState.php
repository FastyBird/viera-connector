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

use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
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
		private readonly DevicesModels\Entities\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesUtilities\ChannelPropertiesStates $channelPropertiesStateManager,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\MalformedInput
	 */
	public function consume(Entities\Messages\Entity $entity): bool
	{
		if (!$entity instanceof Entities\Messages\StoreChannelPropertyState) {
			return false;
		}

		$findDeviceQuery = new Queries\Entities\FindDevices();
		$findDeviceQuery->byConnectorId($entity->getConnector());
		$findDeviceQuery->byId($entity->getDevice());

		$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);

		if ($device === null) {
			$this->logger->error(
				'Device could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'store-channel-property-state-message-consumer',
					'connector' => [
						'id' => $entity->getConnector()->toString(),
					],
					'device' => [
						'id' => $entity->getDevice()->toString(),
					],
					'channel' => [
						'identifier' => $entity->getChannel(),
					],
					'property' => [
						'identifier' => $entity->getProperty(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$findChannelQuery = new DevicesQueries\Entities\FindChannels();
		$findChannelQuery->forDevice($device);
		$findChannelQuery->byIdentifier($entity->getChannel());

		$channel = $this->channelsRepository->findOneBy($findChannelQuery);

		if ($channel === null) {
			$this->logger->error(
				'Channel could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'store-channel-property-state-message-consumer',
					'connector' => [
						'id' => $entity->getConnector()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $entity->getChannel(),
					],
					'property' => [
						'id' => $entity->getProperty(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		$findChannelPropertyQuery = new DevicesQueries\Entities\FindChannelDynamicProperties();
		$findChannelPropertyQuery->forChannel($channel);
		$findChannelPropertyQuery->byIdentifier($entity->getProperty());

		$property = $this->channelsPropertiesRepository->findOneBy(
			$findChannelPropertyQuery,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		if ($property === null) {
			$this->logger->error(
				'Channel property could not be loaded',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'store-channel-property-state-message-consumer',
					'connector' => [
						'id' => $entity->getConnector()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
					],
					'channel' => [
						'id' => $channel->getId()->toString(),
					],
					'property' => [
						'id' => $entity->getProperty(),
					],
					'data' => $entity->toArray(),
				],
			);

			return true;
		}

		if ($property->getDataType()->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)) {
			$this->channelPropertiesStateManager->setValue($property, Utils\ArrayHash::from([
				DevicesStates\Property::ACTUAL_VALUE_FIELD => null,
				DevicesStates\Property::EXPECTED_VALUE_FIELD => null,
				DevicesStates\Property::PENDING_FIELD => false,
				DevicesStates\Property::VALID_FIELD => true,
			]));
		} else {
			$this->channelPropertiesStateManager->setValue($property, Utils\ArrayHash::from([
				DevicesStates\Property::ACTUAL_VALUE_FIELD => DevicesUtilities\ValueHelper::transformValueFromDevice(
					$property->getDataType(),
					$property->getFormat(),
					$entity->getValue(),
				),
				DevicesStates\Property::VALID_FIELD => true,
			]));
		}

		$this->logger->debug(
			'Consumed channel property status message',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'store-channel-property-state-message-consumer',
				'device' => [
					'id' => $device->getId()->toString(),
				],
				'data' => $entity->toArray(),
			],
		);

		return true;
	}

}
