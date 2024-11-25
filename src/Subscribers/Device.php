<?php declare(strict_types = 1);

/**
 * Device.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:DevicesModuleUiModuleBridge!
 * @subpackage     Subscribers
 * @since          1.0.0
 *
 * @date           27.08.24
 */

namespace FastyBird\Connector\Viera\Subscribers;

use Doctrine\DBAL;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Types;
use FastyBird\Core\Tools\Exceptions as ToolsExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Events as DevicesEvents;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use Symfony\Component\EventDispatcher;
use TypeError;
use ValueError;

/**
 * Device events
 *
 * @package        FastyBird:DevicesModuleUiModuleBridge!
 * @subpackage     Subscribers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Device implements EventDispatcher\EventSubscriberInterface
{

	use Nette\SmartObject;

	public function __construct(
		private readonly Helpers\ChannelProperty $channelProperty,
		private readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
	)
	{
	}

	public static function getSubscribedEvents(): array
	{
		return [
			DevicesEvents\EntityUpdated::class => 'updated',
		];
	}

	/**
	 * @throws DBAL\Exception
	 * @throws Exceptions\InvalidArgument
	 * @throws ToolsExceptions\InvalidState
	 * @throws ToolsExceptions\Runtime
	 * @throws TypeError
	 * @throws ValueError
	 */
	public function updated(DevicesEvents\EntityUpdated $event): void
	{
		$entity = $event->getEntity();

		if (!$entity instanceof Entities\Devices\Device) {
			return;
		}

		$this->checkChannelProperties($entity);
	}

	/**
	 * @throws DBAL\Exception
	 * @throws Exceptions\InvalidArgument
	 * @throws ToolsExceptions\InvalidState
	 * @throws ToolsExceptions\Runtime
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function checkChannelProperties(Entities\Devices\Device $device): void
	{
		$findChannelQuery = new Queries\Entities\FindChannels();
		$findChannelQuery->forDevice($device);
		$findChannelQuery->byIdentifier(Types\ChannelType::TELEVISION);

		$channel = $this->channelsRepository->findOneBy($findChannelQuery, Entities\Channels\Channel::class);

		if ($channel === null) {
			return;
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

		$findChannelProperty = new Queries\Entities\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

		$hdmiProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		if ($hdmiProperty === null) {
			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::ENUM,
				Types\ChannelPropertyIdentifier::HDMI,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::HDMI->value),
				null,
				true,
			);
		}

		$findChannelProperty = new Queries\Entities\FindChannelProperties();
		$findChannelProperty->forChannel($channel);
		$findChannelProperty->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

		$appsProperty = $this->channelsPropertiesRepository->findOneBy(
			$findChannelProperty,
			DevicesEntities\Channels\Properties\Dynamic::class,
		);

		if ($appsProperty === null) {
			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::ENUM,
				Types\ChannelPropertyIdentifier::APPLICATION,
				DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::APPLICATION->value),
				null,
				true,
			);
		}

		foreach (Viera\Constants::KEYS_PROPERTIES as $actionKey => $identifier) {
			$this->channelProperty->create(
				DevicesEntities\Channels\Properties\Dynamic::class,
				$channel->getId(),
				null,
				MetadataTypes\DataType::BUTTON,
				$identifier,
				DevicesUtilities\Name::createName($identifier->value),
				[
					[
						MetadataTypes\Payloads\Button::CLICKED->value,
						Types\ActionKey::from($actionKey)->value,
						Types\ActionKey::from($actionKey)->value,
					],
				],
				true,
			);
		}
	}

}
