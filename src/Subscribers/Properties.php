<?php declare(strict_types = 1);

/**
 * Properties.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Subscribers
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Subscribers;

use Doctrine\Common;
use Doctrine\ORM;
use Doctrine\Persistence;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Library\Metadata\ValueObjects as MetadataValueObjects;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use IPub\DoctrineCrud;
use Nette;
use Nette\Utils;
use function array_merge;

/**
 * Doctrine entities events
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Subscribers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Properties implements Common\EventSubscriber
{

	use Nette\SmartObject;

	public function __construct(
		private readonly DevicesModels\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		private readonly DevicesModels\Devices\Properties\PropertiesManager $devicesPropertiesManager,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesManager $channelsPropertiesManager,
	)
	{
	}

	public function getSubscribedEvents(): array
	{
		return [
			ORM\Events::postPersist,
			ORM\Events::postUpdate,
		];
	}

	/**
	 * @param Persistence\Event\LifecycleEventArgs<ORM\EntityManagerInterface> $eventArgs
	 *
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 */
	public function postPersist(Persistence\Event\LifecycleEventArgs $eventArgs): void
	{
		// onFlush was executed before, everything already initialized
		$entity = $eventArgs->getObject();

		// Check for valid entity
		if ($entity instanceof Entities\VieraDevice) {
			$this->configureDeviceState($entity);
		}

		// Check for valid entity
		if (
			$entity instanceof DevicesEntities\Channels\Channel
			&& $entity->getDevice() instanceof Entities\VieraDevice
		) {
			$this->configureDeviceKeys($entity);
		}
	}

	/**
	 * @param Persistence\Event\LifecycleEventArgs<ORM\EntityManagerInterface> $eventArgs
	 *
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws MetadataExceptions\InvalidArgument
	 */
	public function postUpdate(Persistence\Event\LifecycleEventArgs $eventArgs): void
	{
		// onFlush was executed before, everything already initialized
		$entity = $eventArgs->getObject();

		if (
			$entity instanceof DevicesEntities\Channels\Properties\Dynamic
			&& $entity->getChannel()->getDevice() instanceof Entities\VieraDevice
			&& (
				$entity->getIdentifier() === Types\ChannelPropertyIdentifier::HDMI
				|| $entity->getIdentifier() === Types\ChannelPropertyIdentifier::APPLICATION
			)
		) {
			$this->configureDeviceInputSource($entity);
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 */
	private function configureDeviceState(Entities\VieraDevice $device): void
	{
		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::STATE);

		$stateProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($stateProperty !== null && !$stateProperty instanceof DevicesEntities\Devices\Properties\Dynamic) {
			$this->devicesPropertiesManager->delete($stateProperty);

			$stateProperty = null;
		}

		if ($stateProperty !== null) {
			$this->devicesPropertiesManager->update($stateProperty, Utils\ArrayHash::from([
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				'unit' => null,
				'format' => [
					MetadataTypes\ConnectionState::STATE_CONNECTED,
					MetadataTypes\ConnectionState::STATE_DISCONNECTED,
					MetadataTypes\ConnectionState::STATE_LOST,
					MetadataTypes\ConnectionState::STATE_ALERT,
					MetadataTypes\ConnectionState::STATE_UNKNOWN,
				],
				'settable' => false,
				'queryable' => false,
			]));
		} else {
			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'device' => $device,
				'entity' => DevicesEntities\Devices\Properties\Dynamic::class,
				'identifier' => Types\DevicePropertyIdentifier::STATE,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::STATE),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				'unit' => null,
				'format' => [
					MetadataTypes\ConnectionState::STATE_CONNECTED,
					MetadataTypes\ConnectionState::STATE_DISCONNECTED,
					MetadataTypes\ConnectionState::STATE_LOST,
					MetadataTypes\ConnectionState::STATE_ALERT,
					MetadataTypes\ConnectionState::STATE_UNKNOWN,
				],
				'settable' => false,
				'queryable' => false,
			]));
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 */
	private function configureDeviceKeys(DevicesEntities\Channels\Channel $channel): void
	{
		$keysProperties = [
			Types\ActionKey::TV => Types\ChannelPropertyIdentifier::KEY_TV,
			Types\ActionKey::HOME => Types\ChannelPropertyIdentifier::KEY_HOME,
			Types\ActionKey::CH_UP => Types\ChannelPropertyIdentifier::KEY_CHANNEL_UP,
			Types\ActionKey::CH_DOWN => Types\ChannelPropertyIdentifier::KEY_CHANNEL_DOWN,
			Types\ActionKey::VOLUME_UP => Types\ChannelPropertyIdentifier::KEY_VOLUME_UP,
			Types\ActionKey::VOLUME_DOWN => Types\ChannelPropertyIdentifier::KEY_VOLUME_DOWN,
			Types\ActionKey::UP => Types\ChannelPropertyIdentifier::KEY_ARROW_UP,
			Types\ActionKey::DOWN => Types\ChannelPropertyIdentifier::KEY_ARROW_DOWN,
			Types\ActionKey::LEFT => Types\ChannelPropertyIdentifier::KEY_ARROW_LEFT,
			Types\ActionKey::RIGHT => Types\ChannelPropertyIdentifier::KEY_ARROW_RIGHT,
			Types\ActionKey::NUM_0 => Types\ChannelPropertyIdentifier::KEY_0,
			Types\ActionKey::NUM_1 => Types\ChannelPropertyIdentifier::KEY_1,
			Types\ActionKey::NUM_2 => Types\ChannelPropertyIdentifier::KEY_2,
			Types\ActionKey::NUM_3 => Types\ChannelPropertyIdentifier::KEY_3,
			Types\ActionKey::NUM_4 => Types\ChannelPropertyIdentifier::KEY_4,
			Types\ActionKey::NUM_5 => Types\ChannelPropertyIdentifier::KEY_5,
			Types\ActionKey::NUM_6 => Types\ChannelPropertyIdentifier::KEY_6,
			Types\ActionKey::NUM_7 => Types\ChannelPropertyIdentifier::KEY_7,
			Types\ActionKey::NUM_8 => Types\ChannelPropertyIdentifier::KEY_8,
			Types\ActionKey::NUM_9 => Types\ChannelPropertyIdentifier::KEY_9,
			Types\ActionKey::RED => Types\ChannelPropertyIdentifier::KEY_RED,
			Types\ActionKey::GREEN => Types\ChannelPropertyIdentifier::KEY_GREEN,
			Types\ActionKey::YELLOW => Types\ChannelPropertyIdentifier::KEY_YELLOW,
			Types\ActionKey::BLUE => Types\ChannelPropertyIdentifier::KEY_BLUE,
			Types\ActionKey::ENTER => Types\ChannelPropertyIdentifier::KEY_OK,
			Types\ActionKey::RETURN => Types\ChannelPropertyIdentifier::KEY_BACK,
		];

		foreach ($keysProperties as $actionKey => $identifier) {
			$this->processChannelProperty(
				$channel,
				Types\ChannelPropertyIdentifier::get($identifier),
				Types\ActionKey::get($actionKey),
			);
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 */
	private function processChannelProperty(
		DevicesEntities\Channels\Channel $channel,
		Types\ChannelPropertyIdentifier $identifier,
		Types\ActionKey $key,
	): void
	{
		$findChannelPropertyQuery = new DevicesQueries\FindChannelProperties();
		$findChannelPropertyQuery->forChannel($channel);
		$findChannelPropertyQuery->byIdentifier($identifier->getValue());

		$property = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);

		if ($property !== null && !$property instanceof DevicesEntities\Channels\Properties\Dynamic) {
			$this->channelsPropertiesManager->delete($property);

			$property = null;
		}

		if ($property === null) {
			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'channel' => $channel,
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => $identifier->getValue(),
				'name' => Helpers\Name::createName($identifier->getValue()),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BUTTON),
				'unit' => null,
				'format' => [
					[
						MetadataTypes\ButtonPayload::PAYLOAD_CLICKED,
						$key->getValue(),
						$key->getValue(),
					],
				],
				'settable' => true,
				'queryable' => false,
			]));
		} else {
			$this->channelsPropertiesManager->update($property, Utils\ArrayHash::from([
				'name' => Helpers\Name::createName($identifier->getValue()),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BUTTON),
				'unit' => null,
				'format' => [
					$key->getValue(),
				],
				'settable' => true,
				'queryable' => false,
			]));
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws MetadataExceptions\InvalidArgument
	 */
	private function configureDeviceInputSource(DevicesEntities\Channels\Properties\Dynamic $property): void
	{
		$channel = $property->getChannel();

		$findChannelProperty = new DevicesQueries\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

		$hdmiProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		$hdmiFormat = $hdmiProperty?->getFormat();

		$hdmiFormat = $hdmiFormat instanceof MetadataValueObjects\CombinedEnumFormat ? $hdmiFormat->toArray() : [];

		$findChannelProperty = new DevicesQueries\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

		$applicationProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		$applicationFormat = $applicationProperty?->getFormat();

		$applicationFormat = $applicationFormat instanceof MetadataValueObjects\CombinedEnumFormat
			? $applicationFormat->toArray()
			: [];

		$findChannelProperty = new DevicesQueries\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::INPUT_SOURCE);

		$inputSourceProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		if ($inputSourceProperty === null) {
			$this->channelsPropertiesManager->create(
				Utils\ArrayHash::from(
					[
						'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
						'channel' => $channel,
						'identifier' => Types\ChannelPropertyIdentifier::INPUT_SOURCE,
						'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::INPUT_SOURCE),
						'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
						'settable' => true,
						'queryable' => false,
						'format' => array_merge(
							[
								[
									'TV',
									500,
									500,
								],
							],
							$hdmiFormat,
							$applicationFormat,
						),
					],
				),
			);
		} else {
			$this->channelsPropertiesManager->update(
				$inputSourceProperty,
				Utils\ArrayHash::from(
					[
						'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
						'settable' => true,
						'queryable' => false,
						'format' => array_merge(
							[
								[
									'TV',
									500,
									500,
								],
							],
							$hdmiFormat,
							$applicationFormat,
						),
					],
				),
			);
		}
	}

}
