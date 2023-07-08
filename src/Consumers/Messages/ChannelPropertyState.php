<?php declare(strict_types = 1);

/**
 * ChannelPropertyState.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Consumers
 * @since          1.0.0
 *
 * @date           01.07.23
 */

namespace FastyBird\Connector\Viera\Consumers\Messages;

use FastyBird\Connector\Viera\Consumers\Consumer;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\States as DevicesStates;
use Nette;
use Nette\Utils;
use Psr\Log;
use function assert;

/**
 * Channel property status message consumer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Consumers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class ChannelPropertyState implements Consumer
{

	use Nette\SmartObject;

	private Log\LoggerInterface $logger;

	public function __construct(
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly Helpers\Property $propertyStateHelper,
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
	public function consume(Entities\Messages\Entity $entity): bool
	{
		if (!$entity instanceof Entities\Messages\ChannelPropertyState) {
			return false;
		}

		$findDeviceQuery = new DevicesQueries\FindDevices();
		$findDeviceQuery->byConnectorId($entity->getConnector());
		$findDeviceQuery->byIdentifier($entity->getIdentifier());

		$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);

		if ($device === null) {
			return true;
		}

		$findChannelQuery = new DevicesQueries\FindChannels();
		$findChannelQuery->forDevice($device);
		$findChannelQuery->byIdentifier($entity->getChannel());

		$channel = $this->channelsRepository->findOneBy($findChannelQuery);

		if ($channel === null) {
			return true;
		}

		$findChannelPropertyQuery = new DevicesQueries\FindChannelProperties();
		$findChannelPropertyQuery->forChannel($channel);
		$findChannelPropertyQuery->byIdentifier($entity->getProperty());

		$property = $this->channelsPropertiesRepository->findOneBy(
			$findChannelPropertyQuery,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		if ($property === null) {
			return true;
		}

		assert($property instanceof DevicesEntities\Channels\Properties\Dynamic);

		if ($property->getDataType()->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)) {
			$this->propertyStateHelper->setValue($property, Utils\ArrayHash::from([
				DevicesStates\Property::ACTUAL_VALUE_KEY => null,
				DevicesStates\Property::EXPECTED_VALUE_KEY => null,
				DevicesStates\Property::PENDING_KEY => false,
				DevicesStates\Property::VALID_KEY => true,
			]));
		} else {
			$this->propertyStateHelper->setValue($property, Utils\ArrayHash::from([
				DevicesStates\Property::ACTUAL_VALUE_KEY => $entity->getState(),
				DevicesStates\Property::VALID_KEY => true,
			]));
		}

		$this->logger->debug(
			'Consumed channel property status message',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'channel-property-state-message-consumer',
				'device' => [
					'id' => $device->getPlainId(),
				],
				'data' => $entity->toArray(),
			],
		);

		return true;
	}

}
