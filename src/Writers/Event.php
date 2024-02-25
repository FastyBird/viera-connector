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

use FastyBird\Connector\Viera\Documents;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Module\Devices\Events as DevicesEvents;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use Symfony\Component\EventDispatcher;

/**
 * Event based properties writer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Event extends Periodic implements Writer, EventDispatcher\EventSubscriberInterface
{

	public const NAME = 'event';

	public static function getSubscribedEvents(): array
	{
		return [
			DevicesEvents\ChannelPropertyStateEntityCreated::class => 'stateChanged',
			DevicesEvents\ChannelPropertyStateEntityUpdated::class => 'stateChanged',
		];
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 */
	public function stateChanged(
		DevicesEvents\ChannelPropertyStateEntityCreated|DevicesEvents\ChannelPropertyStateEntityUpdated $event,
	): void
	{
		$state = $event->getGet();

		if ($state->getExpectedValue() === null || $state->getPending() !== true) {
			return;
		}

		$findChannelQuery = new Queries\Configuration\FindChannels();
		$findChannelQuery->byId($event->getProperty()->getChannel());

		$channel = $this->channelsConfigurationRepository->findOneBy(
			$findChannelQuery,
			Documents\Channels\Channel::class,
		);

		if ($channel === null) {
			return;
		}

		$findDeviceQuery = new Queries\Configuration\FindDevices();
		$findDeviceQuery->forConnector($this->connector);
		$findDeviceQuery->byId($channel->getDevice());

		$device = $this->devicesConfigurationRepository->findOneBy(
			$findDeviceQuery,
			Documents\Devices\Device::class,
		);

		if ($device === null) {
			return;
		}

		$this->queue->append(
			$this->messageBuilder->create(
				Queue\Messages\WriteChannelPropertyState::class,
				[
					'connector' => $this->connector->getId(),
					'device' => $device->getId(),
					'channel' => $channel->getId(),
					'property' => $event->getProperty()->getId(),
					'state' => $event->getGet()->toArray(),
				],
			),
		);
	}

}
