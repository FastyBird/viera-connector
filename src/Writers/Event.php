<?php declare(strict_types = 1);

/**
 * Event.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Writers;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Module\Devices\Events as DevicesEvents;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use Nette;
use Symfony\Component\EventDispatcher;
use function assert;

/**
 * Event based properties writer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Event implements Writer, EventDispatcher\EventSubscriberInterface
{

	use Nette\SmartObject;

	public const NAME = 'event';

	public function __construct(
		private readonly Entities\VieraConnector $connector,
		private readonly Helpers\Entity $entityHelper,
		private readonly Queue\Queue $queue,
		private readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
	)
	{
	}

	public static function getSubscribedEvents(): array
	{
		return [
			DevicesEvents\ChannelPropertyStateEntityCreated::class => 'stateChanged',
			DevicesEvents\ChannelPropertyStateEntityUpdated::class => 'stateChanged',
		];
	}

	public function connect(): void
	{
		// Nothing to do here
	}

	public function disconnect(): void
	{
		// Nothing to do here
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 */
	public function stateChanged(
		DevicesEvents\ChannelPropertyStateEntityCreated|DevicesEvents\ChannelPropertyStateEntityUpdated $event,
	): void
	{
		$property = $event->getProperty();

		$state = $event->getState();

		if ($state->getExpectedValue() === null || $state->getPending() !== true) {
			return;
		}

		$findChannelQuery = new DevicesQueries\Entities\FindChannels();
		$findChannelQuery->byId($property->getChannel());

		$channel = $this->channelsRepository->findOneBy($findChannelQuery);

		if ($channel === null) {
			return;
		}

		$device = $channel->getDevice();
		assert($device instanceof Entities\VieraDevice);

		if (!$device->getConnector()->getId()->equals($this->connector->getId())) {
			return;
		}

		$this->queue->append(
			$this->entityHelper->create(
				Entities\Messages\WriteChannelPropertyState::class,
				[
					'connector' => $this->connector->getId()->toString(),
					'device' => $device->getId()->toString(),
					'channel' => $channel->getId()->toString(),
					'property' => $property->getId()->toString(),
				],
			),
		);
	}

}
