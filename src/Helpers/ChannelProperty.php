<?php declare(strict_types = 1);

/**
 * ChannelProperty.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 * @since          1.0.0
 *
 * @date           27.08.24
 */

namespace FastyBird\Connector\Viera\Helpers;

use Doctrine\DBAL;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Types;
use FastyBird\Core\Tools\Exceptions as ToolsExceptions;
use FastyBird\Core\Tools\Helpers as ToolsHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Models as DevicesModels;
use Nette\Utils;
use Ramsey\Uuid;
use function array_merge;

final readonly class ChannelProperty
{

	public function __construct(
		private DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		private DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private DevicesModels\Entities\Channels\Properties\PropertiesManager $channelsPropertiesManager,
		private ToolsHelpers\Database $databaseHelper,
		private Viera\Logger $logger,
	)
	{
	}

	/**
	 * @param class-string<DevicesEntities\Channels\Properties\Variable|DevicesEntities\Channels\Properties\Dynamic> $type
	 * @param string|array<int, string>|array<int, string|int|float|array<int, string|int|float>|Utils\ArrayHash|null>|array<int, array<int, string|array<int, string|int|float|bool>|Utils\ArrayHash|null>>|null $format
	 *
	 * @throws DBAL\Exception
	 * @throws Exceptions\InvalidArgument
	 * @throws ToolsExceptions\InvalidState
	 * @throws ToolsExceptions\Runtime
	 */
	public function create(
		string $type,
		Uuid\UuidInterface $channelId,
		string|bool|int|null $value,
		MetadataTypes\DataType $dataType,
		Types\ChannelPropertyIdentifier $identifier,
		string|null $name = null,
		array|string|null $format = null,
		bool $settable = false,
		bool $queryable = false,
	): void
	{
		$findChannelPropertyQuery = new Queries\Entities\FindChannelProperties();
		$findChannelPropertyQuery->byChannelId($channelId);
		$findChannelPropertyQuery->byIdentifier($identifier);

		$property = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);

		if ($property !== null && $value === null && $type === DevicesEntities\Channels\Properties\Variable::class) {
			$this->databaseHelper->transaction(
				function () use ($property): void {
					$this->channelsPropertiesManager->delete($property);
				},
			);

			return;
		}

		if ($value === null && $type === DevicesEntities\Channels\Properties\Variable::class) {
			return;
		}

		if ($property !== null && !$property instanceof $type) {
			$this->databaseHelper->transaction(function () use ($property): void {
				$this->channelsPropertiesManager->delete($property);
			});

			$this->logger->warning(
				'Stored channel property was not of valid type',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'channel-property-helper',
					'channel' => [
						'id' => $channelId->toString(),
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
			$channel = $this->channelsRepository->find($channelId, Viera\Entities\Channels\Channel::class);

			if ($channel === null) {
				$this->logger->error(
					'Channel was not found, property could not be configured',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'channel-property-helper',
						'channel' => [
							'id' => $channelId->toString(),
						],
						'property' => [
							'identifier' => $identifier->value,
						],
					],
				);

				return;
			}

			$property = $this->databaseHelper->transaction(
				fn (): DevicesEntities\Channels\Properties\Property => $this->channelsPropertiesManager->create(
					Utils\ArrayHash::from(array_merge(
						[
							'entity' => $type,
							'channel' => $channel,
							'identifier' => $identifier->value,
							'name' => $name,
							'dataType' => $dataType,
							'format' => $format,
						],
						$type === DevicesEntities\Channels\Properties\Variable::class
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
				'Channel property was created',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'channel-property-helper',
					'channel' => [
						'id' => $channelId->toString(),
					],
					'property' => [
						'id' => $property->getId()->toString(),
						'identifier' => $identifier->value,
					],
				],
			);

		} else {
			$property = $this->databaseHelper->transaction(
				fn (): DevicesEntities\Channels\Properties\Property => $this->channelsPropertiesManager->update(
					$property,
					Utils\ArrayHash::from(array_merge(
						[
							'dataType' => $dataType,
							'format' => $format,
						],
						$type === DevicesEntities\Channels\Properties\Variable::class
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
				'Channel property was updated',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'channel-property-helper',
					'channel' => [
						'id' => $channelId->toString(),
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
