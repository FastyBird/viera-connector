<?php declare(strict_types = 1);

/**
 * DeviceProperty.php
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
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Application\Exceptions as ApplicationExceptions;
use FastyBird\Library\Application\Helpers as ApplicationHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Models as DevicesModels;
use Nette\Utils;
use Ramsey\Uuid;
use function array_merge;

/**
 * Device property consumer trait
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queue
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 *
 * @property-read DevicesModels\Entities\Devices\DevicesRepository $devicesRepository
 * @property-read DevicesModels\Entities\Devices\Properties\PropertiesRepository $devicesPropertiesRepository
 * @property-read DevicesModels\Entities\Devices\Properties\PropertiesManager $devicesPropertiesManager
 * @property-read ApplicationHelpers\Database $databaseHelper
 * @property-read Viera\Logger $logger
 */
trait DeviceProperty
{

	/**
	 * @param class-string<DevicesEntities\Devices\Properties\Variable|DevicesEntities\Devices\Properties\Dynamic> $type
	 * @param string|array<int, string>|array<int, string|int|float|array<int, string|int|float>|Utils\ArrayHash|null>|array<int, array<int, string|array<int, string|int|float|bool>|Utils\ArrayHash|null>>|null $format
	 *
	 * @throws ApplicationExceptions\InvalidState
	 * @throws ApplicationExceptions\Runtime
	 * @throws Exceptions\InvalidArgument
	 * @throws DBAL\Exception
	 */
	private function setDeviceProperty(
		string $type,
		Uuid\UuidInterface $deviceId,
		string|bool|int|null $value,
		MetadataTypes\DataType $dataType,
		Types\DevicePropertyIdentifier $identifier,
		string|null $name = null,
		array|string|null $format = null,
		bool $settable = false,
		bool $queryable = false,
	): void
	{
		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->byDeviceId($deviceId);
		$findDevicePropertyQuery->byIdentifier($identifier);

		$property = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($property !== null && $value === null) {
			$this->databaseHelper->transaction(
				function () use ($property): void {
					$this->devicesPropertiesManager->delete($property);
				},
			);

			return;
		}

		if ($value === null && $type === DevicesEntities\Devices\Properties\Variable::class) {
			return;
		}

		if ($property !== null && !$property instanceof $type) {
			$this->databaseHelper->transaction(function () use ($property): void {
				$this->devicesPropertiesManager->delete($property);
			});

			$this->logger->warning(
				'Stored device property was not of valid type',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'message-consumer',
					'device' => [
						'id' => $deviceId->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
						'identifier' => $identifier->value,
					],
				],
			);

			$property = null;
		}

		if ($property === null) {
			$device = $this->devicesRepository->find(
				$deviceId,
				Entities\Devices\Device::class,
			);

			if ($device === null) {
				$this->logger->error(
					'Device was not found, property could not be configured',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'message-consumer',
						'device' => [
							'id' => $deviceId->toString(),
						],
						'property' => [
							'identifier' => $identifier->value,
						],
					],
				);

				return;
			}

			$property = $this->databaseHelper->transaction(
				fn (): DevicesEntities\Devices\Properties\Property => $this->devicesPropertiesManager->create(
					Utils\ArrayHash::from(array_merge(
						[
							'entity' => $type,
							'device' => $device,
							'identifier' => $identifier->value,
							'name' => $name,
							'dataType' => $dataType,
							'format' => $format,
						],
						$type === DevicesEntities\Devices\Properties\Variable::class
							? [
								'value' => $value,
							]
							: [
								'settable' => $settable,
								'queryable' => $queryable,
							],
					)),
				),
			);

			$this->logger->debug(
				'Device property was created',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'message-consumer',
					'device' => [
						'id' => $deviceId->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
						'identifier' => $identifier->value,
					],
				],
			);

		} else {
			$property = $this->databaseHelper->transaction(
				fn (): DevicesEntities\Devices\Properties\Property => $this->devicesPropertiesManager->update(
					$property,
					Utils\ArrayHash::from(array_merge(
						[
							'dataType' => $dataType,
							'format' => $format,
						],
						$type === DevicesEntities\Devices\Properties\Variable::class
							? [
								'value' => $value,
							]
							: [
								'settable' => $settable,
								'queryable' => $queryable,
							],
					)),
				),
			);

			$this->logger->debug(
				'Device property was updated',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'message-consumer',
					'device' => [
						'id' => $deviceId->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
						'identifier' => $identifier->value,
					],
				],
			);
		}
	}

}
