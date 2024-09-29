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
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Application\Exceptions as ApplicationExceptions;
use FastyBird\Library\Application\Helpers as ApplicationHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
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

	use Nette\SmartObject;

	public function __construct(
		private readonly Viera\Logger $logger,
		private readonly Helpers\DeviceProperty $deviceProperty,
		private readonly Helpers\ChannelProperty $channelProperty,
		private readonly DevicesModels\Entities\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Entities\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Entities\Devices\DevicesManager $devicesManager,
		private readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Entities\Channels\ChannelsManager $channelsManager,
		private readonly ApplicationHelpers\Database $databaseHelper,
	)
	{
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws ApplicationExceptions\Runtime
	 * @throws DBAL\Exception
	 * @throws Exceptions\InvalidArgument
	 */
	public function consume(Queue\Messages\Message $message): bool
	{
		if (!$message instanceof Queue\Messages\StoreDevice) {
			return false;
		}

		$findDeviceQuery = new Queries\Entities\FindDevices();
		$findDeviceQuery->byConnectorId($message->getConnector());
		$findDeviceQuery->byIdentifier($message->getIdentifier());

		$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\Devices\Device::class);

		if ($device === null) {
			$connector = $this->connectorsRepository->find(
				$message->getConnector(),
				Entities\Connectors\Connector::class,
			);

			if ($connector === null) {
				return true;
			}

			$device = $this->databaseHelper->transaction(
				function () use ($message, $connector): Entities\Devices\Device {
					$device = $this->devicesManager->create(Utils\ArrayHash::from([
						'entity' => Entities\Devices\Device::class,
						'connector' => $connector,
						'identifier' => $message->getIdentifier(),
						'name' => $message->getName(),
					]));
					assert($device instanceof Entities\Devices\Device);

					return $device;
				},
			);

			$this->logger->debug(
				'Device was created',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'store-device-message-consumer',
					'connector' => [
						'id' => $message->getConnector()->toString(),
					],
					'device' => [
						'id' => $device->getId()->toString(),
						'identifier' => $message->getIdentifier(),
						'address' => $message->getIpAddress(),
					],
					'data' => $message->toArray(),
				],
			);
		}

		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getIpAddress(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::IP_ADDRESS,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::IP_ADDRESS->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getPort(),
			MetadataTypes\DataType::UINT,
			Types\DevicePropertyIdentifier::PORT,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::PORT->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getModel(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::MODEL,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MODEL->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getManufacturer(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::MANUFACTURER,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MANUFACTURER->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getSerialNumber(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::SERIAL_NUMBER,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::SERIAL_NUMBER->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getMacAddress(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::MAC_ADDRESS,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MAC_ADDRESS->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->isEncrypted(),
			MetadataTypes\DataType::BOOLEAN,
			Types\DevicePropertyIdentifier::ENCRYPTED,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTED->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getAppId(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::APP_ID,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::APP_ID->value),
		);
		$this->deviceProperty->create(
			DevicesEntities\Devices\Properties\Variable::class,
			$device->getId(),
			$message->getEncryptionKey(),
			MetadataTypes\DataType::STRING,
			Types\DevicePropertyIdentifier::ENCRYPTION_KEY,
			DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value),
		);

		$this->databaseHelper->transaction(function () use ($message, $device): bool {
			$findChannelQuery = new Queries\Entities\FindChannels();
			$findChannelQuery->byIdentifier(Types\ChannelType::TELEVISION);
			$findChannelQuery->forDevice($device);

			$channel = $this->channelsRepository->findOneBy($findChannelQuery, Entities\Channels\Channel::class);

			if ($channel === null) {
				$channel = $this->channelsManager->create(Utils\ArrayHash::from([
					'entity' => Entities\Channels\Channel::class,
					'device' => $device,
					'identifier' => Types\ChannelType::TELEVISION->value,
				]));

				$this->logger->debug(
					'Device channel was created',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'store-device-message-consumer',
						'connector' => [
							'id' => $message->getConnector()->toString(),
						],
						'device' => [
							'id' => $device->getId()->toString(),
						],
						'channel' => [
							'id' => $channel->getId()->toString(),
						],
						'data' => $message->toArray(),
					],
				);
			}

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::BOOLEAN,
				Types\ChannelPropertyIdentifier::STATE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::STATE->value),
				null,
				true,
				true,
			);

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::UCHAR,
				Types\ChannelPropertyIdentifier::VOLUME,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::VOLUME->value),
				[
					0,
					100,
				],
				true,
				true,
			);

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::BOOLEAN,
				Types\ChannelPropertyIdentifier::MUTE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::MUTE->value),
				null,
				true,
				true,
			);

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::STRING,
				Types\ChannelPropertyIdentifier::REMOTE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::REMOTE->value),
				null,
				true,
			);

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::ENUM,
				Types\ChannelPropertyIdentifier::HDMI,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::HDMI->value),
				$message->getHdmi() !== [] ? array_map(
					static fn (Queue\Messages\DeviceHdmi|Queue\Messages\DeviceApplication $item): array => [
						Helpers\Name::sanitizeEnumName($item->getName()),
						$item->getId(),
						$item->getId(),
					],
					$message->getHdmi(),
				) : null,
				true,
			);

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::ENUM,
				Types\ChannelPropertyIdentifier::APPLICATION,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::APPLICATION->value),
				$message->getApplications() !== [] ? array_map(
					static fn (Queue\Messages\DeviceHdmi|Queue\Messages\DeviceApplication $item): array => [
						Helpers\Name::sanitizeEnumName($item->getName()),
						$item->getId(),
						$item->getId(),
					],
					$message->getApplications(),
				) : null,
				true,
			);

			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::ENUM,
				Types\ChannelPropertyIdentifier::INPUT_SOURCE,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::INPUT_SOURCE->value),
				array_merge(
					[
						[
							Viera\Constants::TV_IDENTIFIER,
							Viera\Constants::TV_CODE,
							Viera\Constants::TV_CODE,
						],
					],
					array_map(
						static fn (Queue\Messages\DeviceHdmi|Queue\Messages\DeviceApplication $item): array => [
							Helpers\Name::sanitizeEnumName($item->getName()),
							$item->getId(),
							$item->getId(),
						],
						array_merge($message->getHdmi(), $message->getApplications()),
					),
				),
				true,
			);

			return true;
		});

		$this->logger->debug(
			'Consumed store device message',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'store-device-message-consumer',
				'connector' => [
					'id' => $message->getConnector()->toString(),
				],
				'device' => [
					'id' => $device->getId()->toString(),
				],
				'data' => $message->toArray(),
			],
		);

		return true;
	}

}
