<?php declare(strict_types = 1);

/**
 * StoreDevice.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 * @since          1.0.0
 *
 * @date           28.06.23
 */

namespace FastyBird\Connector\Viera\Queue\Consumers;

use Doctrine\DBAL;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use Nette\Utils;
use function array_map;
use function array_merge;
use function assert;

/**
 * Store device details message consumer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class StoreDevice implements Queue\Consumer
{

	use DeviceProperty;
	use ChannelProperty;
	use Nette\SmartObject;

	public function __construct(
		protected readonly Viera\Logger $logger,
		protected readonly DevicesModels\Entities\Devices\DevicesRepository $devicesRepository,
		protected readonly DevicesModels\Entities\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		protected readonly DevicesModels\Entities\Devices\Properties\PropertiesManager $devicesPropertiesManager,
		protected readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		protected readonly DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		protected readonly DevicesModels\Entities\Channels\Properties\PropertiesManager $channelsPropertiesManager,
		protected readonly DevicesUtilities\Database $databaseHelper,
		private readonly DevicesModels\Entities\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Entities\Devices\DevicesManager $devicesManager,
		private readonly DevicesModels\Entities\Channels\ChannelsManager $channelsManager,
	)
	{
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DevicesExceptions\Runtime
	 */
	public function consume(Entities\Messages\Entity $entity): bool
	{
		if (!$entity instanceof Entities\Messages\StoreDevice) {
			return false;
		}

		$findDeviceQuery = new Queries\Entities\FindDevices();
		$findDeviceQuery->byConnectorId($entity->getConnector());
		$findDeviceQuery->byIdentifier($entity->getIdentifier());

		$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);

		if ($device === null) {
			$connector = $this->connectorsRepository->find(
				$entity->getConnector(),
				Entities\VieraConnector::class,
			);

			if ($connector === null) {
				return true;
			}

			$device = $this->databaseHelper->transaction(
				function () use ($entity, $connector): Entities\VieraDevice {
					$device = $this->devicesManager->create(Utils\ArrayHash::from([
						'entity' => Entities\VieraDevice::class,
						'connector' => $connector,
						'identifier' => $entity->getIdentifier(),
						'name' => $entity->getName(),
					]));
					assert($device instanceof Entities\VieraDevice);

					return $device;
				},
			);

			$this->logger->debug(
				'Device was created',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'store-device-message-consumer',
					'device' => [
						'id' => $device->getId()->toString(),
						'identifier' => $entity->getIdentifier(),
						'address' => $entity->getIpAddress(),
					],
					'data' => $entity->toArray(),
				],
			);
		}

		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getIpAddress(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IP_ADDRESS,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::IP_ADDRESS),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getPort(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UINT),
			Types\DevicePropertyIdentifier::PORT,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::PORT),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getModel(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::MODEL,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MODEL),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getManufacturer(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::MANUFACTURER,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MANUFACTURER),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getSerialNumber(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::SERIAL_NUMBER,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::SERIAL_NUMBER),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getMacAddress(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::MAC_ADDRESS,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MAC_ADDRESS),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->isEncrypted(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
			Types\DevicePropertyIdentifier::ENCRYPTED,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTED),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getAppId(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::APP_ID,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::APP_ID),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getEncryptionKey(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::ENCRYPTION_KEY,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTION_KEY),
		);

		$this->databaseHelper->transaction(function () use ($entity, $device): bool {
			$findChannelQuery = new Queries\Entities\FindChannels();
			$findChannelQuery->byIdentifier(Types\ChannelType::TELEVISION);
			$findChannelQuery->forDevice($device);

			$channel = $this->channelsRepository->findOneBy($findChannelQuery, Entities\VieraChannel::class);

			if ($channel === null) {
				$channel = $this->channelsManager->create(Utils\ArrayHash::from([
					'entity' => Entities\VieraChannel::class,
					'device' => $device,
					'identifier' => Types\ChannelType::TELEVISION,
				]));

				$this->logger->debug(
					'Device channel was created',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'store-device-message-consumer',
						'device' => [
							'id' => $device->getId()->toString(),
						],
						'channel' => [
							'id' => $channel->getId()->toString(),
						],
						'data' => $entity->toArray(),
					],
				);
			}

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
				Types\ChannelPropertyIdentifier::STATE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::STATE),
				null,
				true,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UCHAR),
				Types\ChannelPropertyIdentifier::VOLUME,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::VOLUME),
				[
					0,
					100,
				],
				true,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
				Types\ChannelPropertyIdentifier::MUTE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::MUTE),
				null,
				true,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				Types\ChannelPropertyIdentifier::HDMI,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::HDMI),
				$entity->getHdmi() !== [] ? array_map(
					static fn (Entities\Messages\DeviceHdmi|Entities\Messages\DeviceApplication $item): array => [
						Helpers\Name::sanitizeEnumName($item->getName()),
						$item->getId(),
						$item->getId(),
					],
					$entity->getHdmi(),
				) : null,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				Types\ChannelPropertyIdentifier::APPLICATION,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::APPLICATION),
				$entity->getApplications() !== [] ? array_map(
					static fn (Entities\Messages\DeviceHdmi|Entities\Messages\DeviceApplication $item): array => [
						Helpers\Name::sanitizeEnumName($item->getName()),
						$item->getId(),
						$item->getId(),
					],
					$entity->getApplications(),
				) : null,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				Types\ChannelPropertyIdentifier::INPUT_SOURCE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::INPUT_SOURCE),
				array_merge(
					[
						[
							'TV',
							500,
							500,
						],
					],
					array_map(
						static fn (Entities\Messages\DeviceHdmi|Entities\Messages\DeviceApplication $item): array => [
							Helpers\Name::sanitizeEnumName($item->getName()),
							$item->getId(),
							$item->getId(),
						],
						array_merge($entity->getHdmi(), $entity->getApplications()),
					),
				),
				true,
			);

			return true;
		});

		$this->logger->debug(
			'Consumed store device message',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'store-device-message-consumer',
				'device' => [
					'id' => $device->getId()->toString(),
				],
				'data' => $entity->toArray(),
			],
		);

		return true;
	}

}
