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
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Application\Exceptions as ApplicationExceptions;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Formats as MetadataFormats;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Types as DevicesTypes;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use IPub\DoctrineCrud;
use Nette;
use Nette\Utils;
use TypeError;
use ValueError;
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
		private readonly DevicesModels\Entities\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		private readonly DevicesModels\Entities\Devices\Properties\PropertiesManager $devicesPropertiesManager,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesManager $channelsPropertiesManager,
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
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws TypeError
	 * @throws ValueError
	 */
	public function postPersist(Persistence\Event\LifecycleEventArgs $eventArgs): void
	{
		// onFlush was executed before, everything already initialized
		$entity = $eventArgs->getObject();

		// Check for valid entity
		if ($entity instanceof Entities\Devices\Device) {
			$this->configureDeviceState($entity);
		}

		// Check for valid entity
		if (
			$entity instanceof DevicesEntities\Channels\Channel
			&& $entity->getDevice() instanceof Entities\Devices\Device
		) {
			$this->configureDeviceKeys($entity);
		}
	}

	/**
	 * @param Persistence\Event\LifecycleEventArgs<ORM\EntityManagerInterface> $eventArgs
	 *
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	public function postUpdate(Persistence\Event\LifecycleEventArgs $eventArgs): void
	{
		// onFlush was executed before, everything already initialized
		$entity = $eventArgs->getObject();

		if (
			$entity instanceof DevicesEntities\Channels\Properties\Dynamic
			&& $entity->getChannel()->getDevice() instanceof Entities\Devices\Device
			&& (
				$entity->getIdentifier() === Types\ChannelPropertyIdentifier::HDMI->value
				|| $entity->getIdentifier() === Types\ChannelPropertyIdentifier::APPLICATION->value
			)
		) {
			$this->configureDeviceInputSource($entity);
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 */
	private function configureDeviceState(Entities\Devices\Device $device): void
	{
		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::STATE);

		$stateProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($stateProperty !== null && !$stateProperty instanceof DevicesEntities\Devices\Properties\Dynamic) {
			$this->devicesPropertiesManager->delete($stateProperty);

			$stateProperty = null;
		}

		if ($stateProperty !== null) {
			$this->devicesPropertiesManager->update($stateProperty, Utils\ArrayHash::from([
				'dataType' => MetadataTypes\DataType::ENUM,
				'unit' => null,
				'format' => [
					DevicesTypes\ConnectionState::CONNECTED->value,
					DevicesTypes\ConnectionState::DISCONNECTED->value,
					DevicesTypes\ConnectionState::ALERT->value,
					DevicesTypes\ConnectionState::UNKNOWN->value,
				],
				'settable' => false,
				'queryable' => false,
			]));
		} else {
			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'device' => $device,
				'entity' => DevicesEntities\Devices\Properties\Dynamic::class,
				'identifier' => Types\DevicePropertyIdentifier::STATE->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::STATE->value),
				'dataType' => MetadataTypes\DataType::ENUM,
				'unit' => null,
				'format' => [
					DevicesTypes\ConnectionState::CONNECTED->value,
					DevicesTypes\ConnectionState::DISCONNECTED->value,
					DevicesTypes\ConnectionState::ALERT->value,
					DevicesTypes\ConnectionState::UNKNOWN->value,
				],
				'settable' => false,
				'queryable' => false,
			]));
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function configureDeviceKeys(DevicesEntities\Channels\Channel $channel): void
	{
		$keysProperties = [
			Types\ActionKey::TV->value => Types\ChannelPropertyIdentifier::KEY_TV,
			Types\ActionKey::HOME->value => Types\ChannelPropertyIdentifier::KEY_HOME,
			Types\ActionKey::CH_UP->value => Types\ChannelPropertyIdentifier::KEY_CHANNEL_UP,
			Types\ActionKey::CH_DOWN->value => Types\ChannelPropertyIdentifier::KEY_CHANNEL_DOWN,
			Types\ActionKey::VOLUME_UP->value => Types\ChannelPropertyIdentifier::KEY_VOLUME_UP,
			Types\ActionKey::VOLUME_DOWN->value => Types\ChannelPropertyIdentifier::KEY_VOLUME_DOWN,
			Types\ActionKey::UP->value => Types\ChannelPropertyIdentifier::KEY_ARROW_UP,
			Types\ActionKey::DOWN->value => Types\ChannelPropertyIdentifier::KEY_ARROW_DOWN,
			Types\ActionKey::LEFT->value => Types\ChannelPropertyIdentifier::KEY_ARROW_LEFT,
			Types\ActionKey::RIGHT->value => Types\ChannelPropertyIdentifier::KEY_ARROW_RIGHT,
			Types\ActionKey::NUM_0->value => Types\ChannelPropertyIdentifier::KEY_0,
			Types\ActionKey::NUM_1->value => Types\ChannelPropertyIdentifier::KEY_1,
			Types\ActionKey::NUM_2->value => Types\ChannelPropertyIdentifier::KEY_2,
			Types\ActionKey::NUM_3->value => Types\ChannelPropertyIdentifier::KEY_3,
			Types\ActionKey::NUM_4->value => Types\ChannelPropertyIdentifier::KEY_4,
			Types\ActionKey::NUM_5->value => Types\ChannelPropertyIdentifier::KEY_5,
			Types\ActionKey::NUM_6->value => Types\ChannelPropertyIdentifier::KEY_6,
			Types\ActionKey::NUM_7->value => Types\ChannelPropertyIdentifier::KEY_7,
			Types\ActionKey::NUM_8->value => Types\ChannelPropertyIdentifier::KEY_8,
			Types\ActionKey::NUM_9->value => Types\ChannelPropertyIdentifier::KEY_9,
			Types\ActionKey::RED->value => Types\ChannelPropertyIdentifier::KEY_RED,
			Types\ActionKey::GREEN->value => Types\ChannelPropertyIdentifier::KEY_GREEN,
			Types\ActionKey::YELLOW->value => Types\ChannelPropertyIdentifier::KEY_YELLOW,
			Types\ActionKey::BLUE->value => Types\ChannelPropertyIdentifier::KEY_BLUE,
			Types\ActionKey::ENTER->value => Types\ChannelPropertyIdentifier::KEY_OK,
			Types\ActionKey::RETURN->value => Types\ChannelPropertyIdentifier::KEY_BACK,
		];

		foreach ($keysProperties as $actionKey => $identifier) {
			$this->processChannelProperty(
				$channel,
				$identifier,
				Types\ActionKey::from($actionKey),
			);
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 */
	private function processChannelProperty(
		DevicesEntities\Channels\Channel $channel,
		Types\ChannelPropertyIdentifier $identifier,
		Types\ActionKey $key,
	): void
	{
		$findChannelPropertyQuery = new Queries\Entities\FindChannelProperties();
		$findChannelPropertyQuery->forChannel($channel);
		$findChannelPropertyQuery->byIdentifier($identifier);

		$property = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);

		if ($property !== null && !$property instanceof DevicesEntities\Channels\Properties\Dynamic) {
			$this->channelsPropertiesManager->delete($property);

			$property = null;
		}

		if ($property === null) {
			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'channel' => $channel,
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => $identifier->value,
				'name' => DevicesUtilities\Name::createName($identifier->value),
				'dataType' => MetadataTypes\DataType::BUTTON,
				'unit' => null,
				'format' => [
					[
						MetadataTypes\Payloads\Button::CLICKED->value,
						$key->value,
						$key->value,
					],
				],
				'settable' => true,
				'queryable' => false,
			]));
		} else {
			$this->channelsPropertiesManager->update($property, Utils\ArrayHash::from([
				'name' => DevicesUtilities\Name::createName($identifier->value),
				'dataType' => MetadataTypes\DataType::BUTTON,
				'unit' => null,
				'format' => [
					$key->value,
				],
				'settable' => true,
				'queryable' => false,
			]));
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrud\Exceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function configureDeviceInputSource(DevicesEntities\Channels\Properties\Dynamic $property): void
	{
		$channel = $property->getChannel();

		$findChannelProperty = new Queries\Entities\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

		$hdmiProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		$hdmiFormat = $hdmiProperty?->getFormat();

		$hdmiFormat = $hdmiFormat instanceof MetadataFormats\CombinedEnum ? $hdmiFormat->toArray() : [];

		$findChannelProperty = new Queries\Entities\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

		$applicationProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		$applicationFormat = $applicationProperty?->getFormat();

		$applicationFormat = $applicationFormat instanceof MetadataFormats\CombinedEnum
			? $applicationFormat->toArray()
			: [];

		$findChannelProperty = new Queries\Entities\FindChannelProperties();
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
						'identifier' => Types\ChannelPropertyIdentifier::INPUT_SOURCE->value,
						'name' => DevicesUtilities\Name::createName(
							Types\ChannelPropertyIdentifier::INPUT_SOURCE->value,
						),
						'dataType' => MetadataTypes\DataType::ENUM,
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
						'dataType' => MetadataTypes\DataType::ENUM,
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
