<?php declare(strict_types = 1);

/**
 * Periodic.php
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

use DateTimeInterface;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queue;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Metadata\Documents as MetadataDocuments;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use React\EventLoop;
use function array_key_exists;
use function in_array;

/**
 * Periodic properties writer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
abstract class Periodic implements Writer
{

	use Nette\SmartObject;

	private const HANDLER_START_DELAY = 5.0;

	private const HANDLER_DEBOUNCE_INTERVAL = 2_500.0;

	private const HANDLER_PROCESSING_INTERVAL = 0.01;

	private const HANDLER_PENDING_DELAY = 2_000.0;

	/** @var array<string, MetadataDocuments\DevicesModule\Device>  */
	private array $devices = [];

	/** @var array<string, array<string, MetadataDocuments\DevicesModule\ChannelDynamicProperty>>  */
	private array $properties = [];

	/** @var array<string> */
	private array $processedDevices = [];

	/** @var array<string, DateTimeInterface> */
	private array $processedProperties = [];

	private EventLoop\TimerInterface|null $handlerTimer = null;

	/**
	 * @param DevicesModels\Configuration\Devices\Repository<MetadataDocuments\DevicesModule\Device> $devicesConfigurationRepository
	 * @param DevicesModels\Configuration\Channels\Repository<MetadataDocuments\DevicesModule\Channel> $channelsConfigurationRepository
	 * @param DevicesModels\Configuration\Channels\Properties\Repository<MetadataDocuments\DevicesModule\ChannelDynamicProperty> $channelsPropertiesConfigurationRepository
	 */
	public function __construct(
		protected readonly MetadataDocuments\DevicesModule\Connector $connector,
		protected readonly Helpers\Entity $entityHelper,
		protected readonly Queue\Queue $queue,
		protected readonly DevicesModels\Configuration\Devices\Repository $devicesConfigurationRepository,
		protected readonly DevicesModels\Configuration\Channels\Repository $channelsConfigurationRepository,
		private readonly DevicesModels\Configuration\Channels\Properties\Repository $channelsPropertiesConfigurationRepository,
		private readonly DevicesUtilities\ChannelPropertiesStates $channelPropertiesStatesManager,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	public function connect(): void
	{
		$this->processedDevices = [];
		$this->processedProperties = [];

		$findDevicesQuery = new DevicesQueries\Configuration\FindDevices();
		$findDevicesQuery->forConnector($this->connector);

		foreach ($this->devicesConfigurationRepository->findAllBy($findDevicesQuery) as $device) {
			$this->devices[$device->getId()->toString()] = $device;

			if (!array_key_exists($device->getId()->toString(), $this->properties)) {
				$this->properties[$device->getId()->toString()] = [];
			}

			$findChannelsQuery = new DevicesQueries\Configuration\FindChannels();
			$findChannelsQuery->forDevice($device);

			$channels = $this->channelsConfigurationRepository->findAllBy($findChannelsQuery);

			foreach ($channels as $channel) {
				$findChannelPropertiesQuery = new DevicesQueries\Configuration\FindChannelDynamicProperties();
				$findChannelPropertiesQuery->forChannel($channel);

				$properties = $this->channelsPropertiesConfigurationRepository->findAllBy(
					$findChannelPropertiesQuery,
					MetadataDocuments\DevicesModule\ChannelDynamicProperty::class,
				);

				foreach ($properties as $property) {
					if ($property->isSettable()) {
						$this->properties[$device->getId()->toString()][$property->getId()->toString()] = $property;
					}
				}
			}
		}

		$this->eventLoop->addTimer(
			self::HANDLER_START_DELAY,
			function (): void {
				$this->registerLoopHandler();
			},
		);
	}

	public function disconnect(): void
	{
		if ($this->handlerTimer !== null) {
			$this->eventLoop->cancelTimer($this->handlerTimer);

			$this->handlerTimer = null;
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\MalformedInput
	 */
	private function handleCommunication(): void
	{
		foreach ($this->devices as $device) {
			if (!in_array($device->getId()->toString(), $this->processedDevices, true)) {
				$this->processedDevices[] = $device->getId()->toString();

				if ($this->writeChannelsProperty($device)) {
					$this->registerLoopHandler();

					return;
				}
			}
		}

		$this->processedDevices = [];

		$this->registerLoopHandler();
	}

	/**
	 * @throws DevicesExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws MetadataExceptions\MalformedInput
	 */
	private function writeChannelsProperty(MetadataDocuments\DevicesModule\Device $device): bool
	{
		$now = $this->dateTimeFactory->getNow();

		foreach ($this->properties[$device->getId()->toString()] as $property) {
			$debounce = array_key_exists($property->getId()->toString(), $this->processedProperties)
				? $this->processedProperties[$property->getId()->toString()]
				: false;

			if (
				$debounce !== false
				&& (float) $now->format('Uv') - (float) $debounce->format('Uv') < self::HANDLER_DEBOUNCE_INTERVAL
			) {
				continue;
			}

			$this->processedProperties[$property->getId()->toString()] = $now;

			$state = $this->channelPropertiesStatesManager->getValue($property);

			if ($state === null) {
				continue;
			}

			if ($state->getExpectedValue() === null) {
				continue;
			}

			$pending = $state->getPending();

			if (
				$pending === true
				|| (
					$pending instanceof DateTimeInterface
					&& (float) $now->format('Uv') - (float) $pending->format('Uv') > self::HANDLER_PENDING_DELAY
				)
			) {
				$this->queue->append(
					$this->entityHelper->create(
						Entities\Messages\WriteChannelPropertyState::class,
						[
							'connector' => $device->getConnector(),
							'device' => $device->getId(),
							'channel' => $property->getChannel(),
							'property' => $property->getId(),
						],
					),
				);

				return true;
			}
		}

		return false;
	}

	private function registerLoopHandler(): void
	{
		$this->handlerTimer = $this->eventLoop->addTimer(
			self::HANDLER_PROCESSING_INTERVAL,
			function (): void {
				$this->handleCommunication();
			},
		);
	}

}
