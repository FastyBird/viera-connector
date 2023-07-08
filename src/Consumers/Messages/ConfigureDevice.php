<?php declare(strict_types = 1);

/**
 * ConfigureDevice.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Consumers
 * @since          1.0.0
 *
 * @date           28.06.23
 */

namespace FastyBird\Connector\Viera\Consumers\Messages;

use Doctrine\DBAL;
use FastyBird\Connector\Viera\Consumers\Consumer;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use Nette\Utils;
use Psr\Log;
use function array_map;
use function array_merge;
use function assert;

/**
 * New device message consumer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Consumers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class ConfigureDevice implements Consumer
{

	use Nette\SmartObject;
	use ConsumeDeviceProperty;
	use ConsumeChannelProperty;

	private Log\LoggerInterface $logger;

	public function __construct(
		private readonly DevicesModels\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Devices\DevicesManager $devicesManager,
		private readonly DevicesModels\Devices\Properties\PropertiesRepository $propertiesRepository,
		private readonly DevicesModels\Devices\Properties\PropertiesManager $propertiesManager,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Channels\ChannelsManager $channelsManager,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelPropertiesRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesManager $channelsPropertiesManager,
		private readonly DevicesUtilities\Database $databaseHelper,
		Log\LoggerInterface|null $logger = null,
	)
	{
		$this->logger = $logger ?? new Log\NullLogger();
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DevicesExceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	public function consume(Entities\Messages\Entity $entity): bool
	{
		if (!$entity instanceof Entities\Messages\ConfigureDevice) {
			return false;
		}

		$findDeviceQuery = new DevicesQueries\FindDevices();
		$findDeviceQuery->byConnectorId($entity->getConnector());
		$findDeviceQuery->byIdentifier($entity->getIdentifier());

		$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);

		if ($device === null) {
			$findConnectorQuery = new DevicesQueries\FindConnectors();
			$findConnectorQuery->byId($entity->getConnector());

			$connector = $this->connectorsRepository->findOneBy(
				$findConnectorQuery,
				Entities\VieraConnector::class,
			);
			assert($connector instanceof Entities\VieraConnector || $connector === null);

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

			$this->logger->info(
				'Creating new device',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'configure-device-message-consumer',
					'device' => [
						'id' => $device->getPlainId(),
						'identifier' => $entity->getIdentifier(),
						'address' => $entity->getIpAddress(),
					],
				],
			);
		} else {
			$device = $this->databaseHelper->transaction(
				function () use ($entity, $device): Entities\VieraDevice {
					$device = $this->devicesManager->update($device, Utils\ArrayHash::from([
						'name' => $entity->getName(),
					]));
					assert($device instanceof Entities\VieraDevice);

					return $device;
				},
			);

			$this->logger->debug(
				'Device was updated',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'configure-device-message-consumer',
					'device' => [
						'id' => $device->getPlainId(),
					],
				],
			);
		}

		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getIpAddress(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getPort(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UINT),
			Types\DevicePropertyIdentifier::IDENTIFIER_PORT,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_PORT),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getModel(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getManufacturer(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MANUFACTURER,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MANUFACTURER),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getSerialNumber(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_SERIAL_NUMBER,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_SERIAL_NUMBER),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getMacAddress(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->isEncrypted(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
			Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTED,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTED),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getAppId(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_APP_ID,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_APP_ID),
		);
		$this->setDeviceProperty(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$entity->getEncryptionKey(),
			MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
			Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTION_KEY,
			Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTION_KEY),
		);

		$this->databaseHelper->transaction(function () use ($entity, $device): bool {
			$findChannelQuery = new DevicesQueries\FindChannels();
			$findChannelQuery->byIdentifier(Types\ChannelType::TELEVISION);
			$findChannelQuery->forDevice($device);

			$channel = $this->channelsRepository->findOneBy($findChannelQuery);

			if ($channel === null) {
				$channel = $this->channelsManager->create(Utils\ArrayHash::from([
					'device' => $device,
					'identifier' => Types\ChannelType::TELEVISION,
				]));

				$this->logger->debug(
					'Creating new device channel',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'configure-device-message-consumer',
						'device' => [
							'id' => $device->getPlainId(),
						],
						'channel' => [
							'id' => $channel->getPlainId(),
						],
					],
				);
			}

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
				Types\ChannelPropertyIdentifier::IDENTIFIER_STATE,
				Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_STATE),
				null,
				true,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UCHAR),
				Types\ChannelPropertyIdentifier::IDENTIFIER_VOLUME,
				Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_VOLUME),
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
				Types\ChannelPropertyIdentifier::IDENTIFIER_MUTE,
				Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_MUTE),
				null,
				true,
				true,
			);

			$this->setChannelProperty(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI,
				Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI),
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
				Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION,
				Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION),
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
				Types\ChannelPropertyIdentifier::IDENTIFIER_INPUT_SOURCE,
				Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_INPUT_SOURCE),
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
			'Consumed device found message',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'configure-device-message-consumer',
				'device' => [
					'id' => $device->getPlainId(),
				],
				'data' => $entity->toArray(),
			],
		);

		return true;
	}

}
